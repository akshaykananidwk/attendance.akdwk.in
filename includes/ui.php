<?php
/**
 * Small UI helpers for the v3 design: hero banner, stat cards, avatars, inline SVG illustrations.
 */

function ui_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Hero banner. $actionsHtml is trusted HTML (buttons / chips) rendered on the right. */
function ui_hero(string $title, string $subtitle = '', string $icon = '', string $art = 'temple', string $actionsHtml = ''): string
{
    $artSvg = $art !== '' ? ui_art($art) : '';

    return '<div class="ui-hero' . ($artSvg ? ' has-art' : '') . ($actionsHtml !== '' ? ' has-actions' : '') . '">'
        . '<div style="position:relative;z-index:2"><h1>' . ($icon ? '<i class="bi ' . ui_e($icon) . '"></i>' : '') . $title . '</h1>'
        . ($subtitle !== '' ? '<p>' . $subtitle . '</p>' : '') . '</div>'
        . $artSvg
        . ($actionsHtml !== '' ? '<div class="ui-hero-actions">' . $actionsHtml . '</div>' : '')
        . '</div>';
}

function ui_stat($value, string $label, string $icon, string $color = 'blue', string $href = ''): string
{
    $tag = $href !== '' ? 'a href="' . ui_e($href) . '"' : 'div';
    $end = $href !== '' ? 'a' : 'div';

    return '<' . $tag . ' class="ui-stat ui-c-' . ui_e($color) . '"><span class="ui-stat-icon"><i class="bi ' . ui_e($icon) . '"></i></span>'
        . '<span><div class="ui-stat-val">' . ui_e($value) . '</div><div class="ui-stat-label">' . ui_e($label) . '</div></span></' . $end . '>';
}

function ui_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));

    return $i !== '' ? $i : '?';
}

function ui_avatar(string $name, string $size = ''): string
{
    $colors = ['#3b5bdb', '#6d28d9', '#b45309', '#15803d', '#0369a1', '#be123c', '#0f766e', '#7e22ce'];
    $c = $colors[abs(crc32($name)) % count($colors)];

    return '<span class="ui-avatar ' . ui_e($size) . '" style="background:' . $c . '">' . ui_e(ui_initials($name)) . '</span>';
}

/** Decorative line-art illustrations (inline SVG, theme friendly). */
function ui_art(string $name): string
{
    $stroke = '#3b5bd6';
    $fill = '#c9d8ff';
    $svg = '';
    switch ($name) {
        case 'temple': // stylised Dwarka temple skyline
            $svg = '<svg viewBox="0 0 320 130" class="ui-hero-art" aria-hidden="true">'
                . '<g fill="none" stroke="' . $stroke . '" stroke-width="1.4" stroke-linejoin="round">'
                . '<path d="M40 128h240" stroke-width="2"/>'
                . '<path d="M70 128v-22h180v22"/><path d="M80 106v-10h160v10"/>'
                . '<path d="M100 96v-16h40v16M180 96v-16h40v16"/>'
                . '<path d="M104 80q16-26 32 0M184 80q16-26 32 0"/>'
                . '<path d="M140 96V64h40v32"/><path d="M136 64q24-60 48 0z" fill="' . $fill . '" fill-opacity=".45"/>'
                . '<path d="M146 64q14-36 28 0M152 64q8-22 16 0"/>'
                . '<path d="M160 22V4"/><path d="M160 4l18 5-18 5" fill="#f87171" stroke="#ef4444"/>'
                . '<path d="M150 128v-18q10-8 20 0v18"/>'
                . '<path d="M88 106v22M232 106v22M110 106v22M210 106v22"/>'
                . '<path d="M10 128q30-14 60-4M250 124q30-10 60 4" stroke-opacity=".5"/>'
                . '<path d="M40 40l6 4 6-4M60 30l5 3 5-3M250 48l6 4 6-4M268 36l5 3 5-3" stroke-opacity=".7"/>'
                . '</g></svg>';
            break;
        case 'store':
            $svg = '<svg viewBox="0 0 320 130" class="ui-hero-art" aria-hidden="true"><g stroke="' . $stroke . '" stroke-width="1.4" fill="none" stroke-linejoin="round">'
                . '<path d="M20 128h280" stroke-width="2"/>'
                . '<rect x="110" y="56" width="110" height="72" rx="4" fill="#fff"/>'
                . '<path d="M104 56h122l-8-20H112z" fill="#60a5fa" fill-opacity=".55"/>'
                . '<path d="M112 36v-6h106v6"/><rect x="122" y="72" width="28" height="22" fill="' . $fill . '"/><rect x="180" y="72" width="28" height="22" fill="' . $fill . '"/>'
                . '<rect x="153" y="94" width="24" height="34" fill="#dbeafe"/>'
                . '<circle cx="165" cy="14" r="9" fill="#3b82f6" stroke="#1d4ed8"/><path d="M165 23v8" stroke="#1d4ed8"/>'
                . '<circle cx="80" cy="96" r="16" fill="#86efac" fill-opacity=".7" stroke="#16a34a"/><path d="M80 112v16" stroke="#16a34a"/>'
                . '<circle cx="250" cy="96" r="16" fill="#86efac" fill-opacity=".7" stroke="#16a34a"/><path d="M250 112v16" stroke="#16a34a"/>'
                . '</g></svg>';
            break;
        case 'pin':
            $svg = '<svg viewBox="0 0 320 130" class="ui-hero-art" aria-hidden="true"><g stroke="' . $stroke . '" stroke-width="1.4" fill="none">'
                . '<path d="M20 128h280" stroke-width="2"/><ellipse cx="160" cy="118" rx="60" ry="8" fill="' . $fill . '" fill-opacity=".4"/>'
                . '<path d="M160 116c-26-30-36-46-36-62a36 36 0 0 1 72 0c0 16-10 32-36 62z" fill="#3b82f6" fill-opacity=".85" stroke="#1d4ed8"/>'
                . '<circle cx="160" cy="54" r="13" fill="#fff" stroke="#1d4ed8"/>'
                . '<path d="M40 110l40-26 30 14 40-30M200 70l30 16 40-24 30 14" stroke-opacity=".45" stroke-dasharray="4 4"/>'
                . '</g></svg>';
            break;
        case 'clipboard':
            $svg = '<svg viewBox="0 0 320 130" class="ui-hero-art" aria-hidden="true"><g stroke="' . $stroke . '" stroke-width="1.4" fill="none">'
                . '<path d="M60 128c20-30 50-40 70-40M260 128c-20-30-50-40-70-40" stroke="#16a34a" stroke-opacity=".6"/>'
                . '<path d="M80 120q10-40 40-50M240 120q-10-40-40-50" stroke="#16a34a" stroke-opacity=".5"/>'
                . '<rect x="118" y="20" width="84" height="106" rx="10" fill="#fff"/><rect x="140" y="12" width="40" height="16" rx="5" fill="#60a5fa"/>'
                . '<circle cx="160" cy="58" r="14" fill="#3b82f6" stroke="#1d4ed8"/><path d="M138 96q22-24 44 0" fill="#3b82f6" stroke="#1d4ed8"/>'
                . '<path d="M136 108h48M136 116h32" stroke-opacity=".6"/></g></svg>';
            break;
        case 'chart':
            $svg = '<svg viewBox="0 0 320 130" class="ui-hero-art" aria-hidden="true"><g stroke="' . $stroke . '" stroke-width="1.4" fill="none">'
                . '<path d="M60 128h200" stroke-width="2"/><rect x="90" y="80" width="22" height="48" fill="' . $fill . '"/><rect x="126" y="56" width="22" height="72" fill="#93c5fd"/>'
                . '<rect x="162" y="68" width="22" height="60" fill="' . $fill . '"/><rect x="198" y="34" width="22" height="94" fill="#60a5fa"/>'
                . '<path d="M86 70l40-24 36 12 50-34" stroke="#16a34a" stroke-width="2"/><circle cx="212" cy="24" r="4" fill="#16a34a" stroke="#16a34a"/></g></svg>';
            break;
        case 'gear':
            $svg = '<svg viewBox="0 0 320 130" class="ui-hero-art" aria-hidden="true"><g stroke="' . $stroke . '" stroke-width="1.4" fill="none">'
                . '<circle cx="150" cy="66" r="34" fill="' . $fill . '" fill-opacity=".5"/><circle cx="150" cy="66" r="12" fill="#fff"/>'
                . '<path d="M150 22v12M150 98v12M106 66h12M182 66h12M119 35l8 8M173 89l8 8M119 97l8-8M173 43l8-8" stroke-width="5" stroke-linecap="round"/>'
                . '<circle cx="222" cy="96" r="18" fill="#93c5fd" fill-opacity=".6"/><circle cx="222" cy="96" r="6" fill="#fff"/></g></svg>';
            break;
    }

    return $svg;
}
