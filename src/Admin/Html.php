<?php

declare(strict_types=1);

namespace VamsysMcp\Admin;

use VamsysMcp\App;

/** HTML building blocks for the admin UI. Every dynamic value goes through e(). */
final class Html
{
    public function __construct(public readonly string $nonce)
    {
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<array{0: string, 1: string}> $flashes
     */
    public function page(string $title, string $body, array $flashes = [], bool $nav = false): string
    {
        $flashHtml = '';
        foreach ($flashes as [$type, $message]) {
            $flashHtml .= '<div class="flash ' . self::e($type) . '">' . self::e($message) . '</div>';
        }
        $navHtml = $nav ? '<nav><a href="?p=dashboard">Dashboard</a><a href="?p=settings">Settings</a><a href="?p=tokens">Access tokens</a>'
            . '<a href="?p=ftp">FTP</a><a href="?p=update">Updates</a>'
            . '<form method="post" action="?a=logout" class="inline">' . $this->csrfField() . '<button class="link">Log out</button></form></nav>' : '';
        $version = self::e(App::VERSION);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="robots" content="noindex,nofollow"><title>{$this->e($title)} · vAMSYS MCP</title>
            <style nonce="{$this->nonce}">
            body{font:15px/1.5 system-ui,sans-serif;max-width:860px;margin:0 auto;padding:1rem;color:#1d2330;background:#f6f7f9}
            header{display:flex;justify-content:space-between;align-items:baseline;border-bottom:1px solid #d8dbe2;margin-bottom:1rem}
            header small{color:#6b7280}nav{display:flex;gap:1rem;flex-wrap:wrap;margin:.5rem 0 1rem}nav a{color:#1f4fd1}
            fieldset{border:1px solid #d8dbe2;border-radius:6px;background:#fff;margin:0 0 1rem;padding:.75rem 1rem}
            legend{font-weight:600;padding:0 .3rem}label{display:block;margin:.4rem 0}label span{display:block;font-size:.9em;color:#374151}
            input[type=text],input[type=password],input[type=number],select,textarea{width:100%;box-sizing:border-box;padding:.4rem;border:1px solid #c3c8d2;border-radius:4px;font:inherit}
            label.check{display:flex;gap:.5rem;align-items:center}.help{color:#6b7280;font-size:.85em;margin:.1rem 0 .4rem}
            button,.button{background:#1f4fd1;color:#fff;border:0;border-radius:4px;padding:.5rem 1rem;font:inherit;cursor:pointer}
            button.secondary{background:#e5e7eb;color:#1d2330}button.danger{background:#b42318}button.link{background:none;color:#1f4fd1;padding:0;text-decoration:underline}
            form.inline{display:inline}.flash{padding:.6rem .8rem;border-radius:4px;margin:0 0 1rem}.flash.ok{background:#e7f6ec}.flash.error{background:#fdecea}.flash.warn{background:#fff4e5}
            code,pre{background:#eef0f4;padding:.1rem .3rem;border-radius:3px;word-break:break-all}pre{padding:.6rem;white-space:pre-wrap}
            table{border-collapse:collapse;width:100%;background:#fff}td,th{border:1px solid #d8dbe2;padding:.35rem .5rem;text-align:left;vertical-align:top}
            .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0 1rem}.hidden{display:none}progress{width:100%}
            </style></head><body>
            <header><h1>vAMSYS MCP admin</h1><small>v{$version} · unofficial, not affiliated with vAMSYS</small></header>
            {$navHtml}{$flashHtml}<h2>{$this->e($title)}</h2>
            {$body}
            </body></html>
            HTML;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(Session::csrf()) . '">';
    }

    public function input(string $label, string $name, mixed $value = '', string $type = 'text', string $help = '', string $extra = ''): string
    {
        $autocomplete = $type === 'password' ? ' autocomplete="new-password"' : '';

        return '<label><span>' . self::e($label) . '</span><input type="' . self::e($type) . '" name="' . self::e($name) . '" value="'
            . ($type === 'password' ? '' : self::e($value)) . '"' . $autocomplete . $extra . '></label>'
            . ($help !== '' ? '<div class="help">' . self::e($help) . '</div>' : '');
    }

    public function checkbox(string $label, string $name, bool $checked, string $help = ''): string
    {
        return '<label class="check"><input type="hidden" name="' . self::e($name) . '" value="0"><input type="checkbox" name="' . self::e($name) . '" value="1"'
            . ($checked ? ' checked' : '') . '> ' . self::e($label) . '</label>'
            . ($help !== '' ? '<div class="help">' . self::e($help) . '</div>' : '');
    }

    /**
     * @param array<string, string> $options
     */
    public function select(string $label, string $name, array $options, string $selected): string
    {
        $html = '<label><span>' . self::e($label) . '</span><select name="' . self::e($name) . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::e($text) . '</option>';
        }

        return $html . '</select></label>';
    }
}
