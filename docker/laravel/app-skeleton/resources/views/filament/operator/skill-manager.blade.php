<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Where these come from
        </x-slot>

        <x-slot name="description">
            A skill is a folder holding one SKILL.md: YAML frontmatter with a name and a
            description, then the instructions. The folder name is derived from the
            frontmatter name, never from anything else. Every estate reads this one
            directory, so these are deployment-wide — an install here changes what every
            assistant can be told to do.
        </x-slot>

        <div class="text-sm">
            <span class="text-gray-500 dark:text-gray-400">Directory:</span>
            <code class="ml-1">{{ $this->skillsPath() }}</code>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
