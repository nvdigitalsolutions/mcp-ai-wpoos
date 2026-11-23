<?php

namespace App\Support\Skills;

use App\Support\Admin\AdminAuditLogger;
use Nvoos\Core\Application\Skill\SkillRegistry;
use RuntimeException;

/**
 * Install, replace and delete Agent Skills — Proposal 149 §190.
 *
 * `SkillCatalogue` has been read-only since §49, and said why: WordPress ships a
 * manager (install from raw SKILL.md, replace, delete), and "those are file writes
 * driven by HTTP input and deserve their own security pass". This is that pass.
 *
 * ## What makes an HTTP-driven file write safe
 *
 * OWASP's file-upload guidance reduces to four rules, and each one is a decision
 * here rather than a checkbox:
 *
 * | Rule | Here |
 * |---|---|
 * | never trust the client's filename | there is no filename: the folder name is derived from the **parsed** frontmatter `name`, slugified, and must match `SLUG_PATTERN` |
 * | never trust the client's content type | there is no upload and no MIME type; the only accepted input is text that parses as SKILL.md |
 * | keep the write inside a known directory | the slug cannot contain a separator, and `assertInsideRoot()` resolves the target again before any write |
 * | bound what can be written | `MAX_BYTES`, `MAX_NAME_LENGTH`, `MAX_DESCRIPTION_LENGTH` |
 *
 * ## Two more that are not in the list but matter here
 *
 * **Atomicity.** The file is written to a temp name in the root and `rename()`d
 * into place, so a crash mid-write cannot leave a half-parsed skill that the
 * catalogue then reports. `rename` within one filesystem is atomic.
 *
 * **Symlinks.** A folder that is a symlink is refused rather than followed. The
 * deployment owns its own directory layout; a link inside it is either a mistake
 * or an attempt to make this writer touch something outside the root.
 *
 * ## Delete is narrow on purpose
 *
 * It removes `SKILL.md` and then the folder, and **only** when `SKILL.md` is the
 * only thing in it. A recursive delete of a path named by HTTP input is the
 * classic way an installer becomes a remote file deletion bug; if an operator has
 * extra files in a skill folder, the honest answer is "remove it on the server",
 * not "this panel will guess which files you meant".
 *
 * ## Every write is audited
 *
 * Livewire traffic is excluded from the request-level audit (plan §25), so each
 * mutation records itself through `AdminAuditLogger`. A skill changes what every
 * assistant in the deployment reads, which is exactly the kind of change that
 * needs an actor attached to it.
 */
final class SkillInstaller
{
    /** A SKILL.md is instructions, not a corpus. 256 KB is generous. */
    public const MAX_BYTES = 262144;

    public const MAX_NAME_LENGTH = 80;

    public const MAX_DESCRIPTION_LENGTH = 1024;

    /** The folder name, and therefore the only path segment accepted. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function __construct(
        private readonly string $root,
        private readonly AdminAuditLogger $audit,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('oos.skills.path', storage_path('app/skills')),
            app(AdminAuditLogger::class)
        );
    }

    /**
     * The configured directory, for the page header.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Store a SKILL.md under a folder named after its own `name`.
     *
     * @param string $raw     The whole file, frontmatter and body.
     * @param bool   $replace Overwrite an installed skill with the same slug.
     * @return array{slug: string, name: string, replaced: bool}
     * @throws SkillInstallInvalid The input cannot be a skill, or must not be written.
     */
    public function install(string $raw, bool $replace = false): array
    {
        // Normalise line endings first: the parser's frontmatter regex anchors on
        // `\n`, so a CRLF paste would otherwise be rejected as "no frontmatter".
        $raw = str_replace(array("\r\n", "\r"), "\n", $raw);
        $bytes = strlen($raw);

        if (0 === $bytes) {
            throw new SkillInstallInvalid('Paste a SKILL.md to install.');
        }

        if ($bytes > self::MAX_BYTES) {
            throw new SkillInstallInvalid(sprintf(
                'A SKILL.md may be at most %d KB; this one is %d KB.',
                intdiv(self::MAX_BYTES, 1024),
                (int) ceil($bytes / 1024)
            ));
        }

        $parsed = SkillRegistry::parseSkillMd($raw);

        if (null === $parsed) {
            throw new SkillInstallInvalid(
                'A SKILL.md needs YAML frontmatter started and closed by ---, with a name.'
            );
        }

        $name = trim((string) $parsed['name']);
        $description = trim((string) $parsed['description']);

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new SkillInstallInvalid(sprintf(
                'The name may be at most %d characters.',
                self::MAX_NAME_LENGTH
            ));
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new SkillInstallInvalid(sprintf(
                'The description may be at most %d characters; it is injected into every prompt that lists skills.',
                self::MAX_DESCRIPTION_LENGTH
            ));
        }

        $slug = $this->slug($name);

        $this->ensureRoot();

        $target = $this->root.'/'.$slug;

        if (is_link($target)) {
            throw new SkillInstallInvalid(
                'That skill folder is a symbolic link. Replace it on the server rather than through this panel.'
            );
        }

        if (file_exists($target) && ! is_dir($target)) {
            throw new SkillInstallInvalid(sprintf('"%s" exists and is not a directory.', $slug));
        }

        $exists = is_file($target.'/SKILL.md');

        if ($exists && ! $replace) {
            throw new SkillInstallInvalid(sprintf(
                '"%s" is already installed. Use Replace if you meant to overwrite it.',
                $slug
            ));
        }

        if (! is_dir($target) && ! @mkdir($target, 0755, true) && ! is_dir($target)) {
            throw new RuntimeException(sprintf('Could not create the folder for "%s".', $slug));
        }

        $this->assertInsideRoot($target);

        $temp = $this->root.'/.'.$slug.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (false === @file_put_contents($temp, $raw)) {
            throw new RuntimeException(sprintf('Could not write the file for "%s".', $slug));
        }

        if (! @rename($temp, $target.'/SKILL.md')) {
            @unlink($temp);

            throw new RuntimeException(sprintf('Could not install "%s".', $slug));
        }

        $this->audit->recordResource(
            'skill',
            $slug,
            $exists ? 'replaced' : 'installed',
            array(),
            array('name' => $name, 'bytes' => $bytes)
        );

        return array('slug' => $slug, 'name' => $name, 'replaced' => $exists);
    }

    /**
     * Remove a skill folder that holds nothing but its SKILL.md.
     *
     * @throws SkillInstallInvalid No such skill, or it holds files this panel did not write.
     */
    public function delete(string $slug): void
    {
        $slug = trim($slug);

        if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            throw new SkillInstallInvalid('That is not a skill folder name.');
        }

        $this->ensureRoot();

        $target = $this->root.'/'.$slug;

        if (is_link($target)) {
            throw new SkillInstallInvalid('That skill folder is a symbolic link; refusing to delete through it.');
        }

        if (! is_dir($target)) {
            throw new SkillInstallInvalid(sprintf('No skill folder named "%s".', $slug));
        }

        $this->assertInsideRoot($target);

        // Check before deleting anything: a failure here must leave the folder
        // untouched rather than half-removed.
        $entries = array_values(array_diff(scandir($target) ?: array(), array('.', '..')));

        if (array('SKILL.md') !== $entries) {
            throw new SkillInstallInvalid(
                'That folder holds files this panel did not write. Remove it on the server instead.'
            );
        }

        if (! @unlink($target.'/SKILL.md')) {
            throw new RuntimeException(sprintf('Could not delete "%s/SKILL.md".', $slug));
        }

        if (! @rmdir($target)) {
            throw new RuntimeException(sprintf('Could not remove the empty folder "%s".', $slug));
        }

        $this->audit->recordResource('skill', $slug, 'deleted', array('name' => $slug), array());
    }

    /**
     * Turn a display name into the folder name.
     *
     * The name is the only thing an operator types, so it has to survive being
     * used as a path segment: letters and digits, single dashes between them, 64
     * characters at most.
     *
     * @throws SkillInstallInvalid The name slugifies to nothing usable.
     */
    private function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 64);
        $slug = rtrim($slug, '-');

        if ('' === $slug || 1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            throw new SkillInstallInvalid(
                'The name must contain a letter or digit after slugifying: it becomes the folder name.'
            );
        }

        return $slug;
    }

    private function ensureRoot(): void
    {
        if ('' === trim($this->root)) {
            throw new SkillInstallInvalid('No skills directory is configured (config oos.skills.path).');
        }

        if (is_dir($this->root)) {
            return;
        }

        if (! @mkdir($this->root, 0755, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Could not create the skills directory.');
        }
    }

    /**
     * Re-resolve the path and refuse anything that escapes the root.
     *
     * The slug already cannot carry a separator; this is the second lock, for the
     * case where the *root* itself is a symlink and the resolution lands somewhere
     * the operator did not intend.
     */
    private function assertInsideRoot(string $path): void
    {
        $root = realpath($this->root);
        $real = realpath($path);

        if (false === $root || false === $real) {
            throw new SkillInstallInvalid('The skills directory could not be resolved.');
        }

        if (! str_starts_with($real.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw new SkillInstallInvalid('That path resolves outside the skills directory.');
        }
    }
}
