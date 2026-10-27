<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

/**
 * Reads CHANGELOG.md (Keep a Changelog: "## [X.Y.Z] - date" sections, each
 * starting with "### Breaking changes") so the updater can show everything
 * between the installed version and the one being installed.
 */
final class Changelog
{
    private const int MAX_SECTION_CHARS = 5000;

    /**
     * Sections of the versions newer than $installed, up to and including
     * $latest, newest first.
     *
     * @return list<array{version: string, date: string, text: string, breaking: bool}>
     */
    public static function between(string $markdown, string $installed, string $latest): array
    {
        $parts = preg_split('/^## \[(\d+\.\d+\.\d+)\](?: - (\S+))?[^\S\n]*$/m', str_replace("\r\n", "\n", $markdown), -1, PREG_SPLIT_DELIM_CAPTURE);
        $sections = [];
        for ($i = 1; $i + 2 < count($parts); $i += 3) {
            $version = $parts[$i];
            if (!version_compare($version, $installed, '>') || version_compare($version, $latest, '>')) {
                continue;
            }
            $text = trim((string) preg_replace('/^\[[^\]]+\]: \S+$/m', '', $parts[$i + 2]));
            $sections[] = [
                'version' => $version,
                'date' => $parts[$i + 1],
                'text' => mb_substr($text, 0, self::MAX_SECTION_CHARS),
                'breaking' => self::hasBreakingChanges($text),
            ];
        }

        return $sections;
    }

    /** True unless the "### Breaking changes" section is missing or starts with "None". */
    private static function hasBreakingChanges(string $section): bool
    {
        if (preg_match('/^### Breaking changes\s*\n(.*?)(?=^### |\z)/ms', $section, $m) !== 1) {
            return false;
        }

        return !str_starts_with(trim($m[1]), 'None');
    }
}
