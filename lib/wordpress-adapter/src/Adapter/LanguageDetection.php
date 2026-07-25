<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\LanguageDetectionInterface;

final class LanguageDetection implements LanguageDetectionInterface {
    private $legacy;
    private static $isoNames = ['af'=>'Afrikaans','ar'=>'Arabic','bg'=>'Bulgarian','bn'=>'Bengali','ca'=>'Catalan','cs'=>'Czech','cy'=>'Welsh','da'=>'Danish','de'=>'German','el'=>'Greek','en'=>'English','es'=>'Spanish','et'=>'Estonian','eu'=>'Basque','fa'=>'Persian','fi'=>'Finnish','fr'=>'French','ga'=>'Irish','gl'=>'Galician','gu'=>'Gujarati','he'=>'Hebrew','hi'=>'Hindi','hr'=>'Croatian','hu'=>'Hungarian','id'=>'Indonesian','is'=>'Icelandic','it'=>'Italian','ja'=>'Japanese','kn'=>'Kannada','ko'=>'Korean','lt'=>'Lithuanian','lv'=>'Latvian','mk'=>'Macedonian','ml'=>'Malayalam','mr'=>'Marathi','ms'=>'Malay','mt'=>'Maltese','nb'=>'Norwegian','nl'=>'Dutch','no'=>'Norwegian','pa'=>'Punjabi','pl'=>'Polish','pt'=>'Portuguese','ro'=>'Romanian','ru'=>'Russian','sk'=>'Slovak','sl'=>'Slovenian','sq'=>'Albanian','sr'=>'Serbian','sv'=>'Swedish','ta'=>'Tamil','te'=>'Telugu','th'=>'Thai','tr'=>'Turkish','uk'=>'Ukrainian','ur'=>'Urdu','vi'=>'Vietnamese','zh'=>'Chinese'];
    public function __construct() { if (\class_exists('WP_MCP_AI_Language_Detection_Service')) { $this->legacy = new \WP_MCP_AI_Language_Detection_Service(); } }
    public function detect(string $text): array {
        if ($this->legacy && \method_exists($this->legacy, 'detect')) {
            try { $r = $this->legacy->detect($text); return \is_array($r) ? $r : ['language' => 'en', 'confidence' => 0.0]; }
            catch (\Throwable $e) {}
        }
        return ['language' => 'en', 'confidence' => 0.0];
    }
    public function getLanguageName(string $isoCode): string { return self::$isoNames[$isoCode] ?? $isoCode; }
    public function getSupportedLanguages(): array { return \array_keys(self::$isoNames); }
}
