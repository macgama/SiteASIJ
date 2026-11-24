<?php
declare(strict_types=1);
/** Illustration (trait) d'une rubrique. @var string $icon */
$paths = [
    'people' => '<circle cx="22" cy="22" r="8"/><circle cx="43" cy="24" r="6.5"/><path d="M8 50c1.5-9 7-14 14-14s12.5 5 14 14M35 49c1-6.5 4.5-10.5 9-10.5s8 4 9 10.5"/>',
    'school' => '<path d="M8 54h48M12 54V30l20-14 20 14v24M26 54V42h12v12M32 16V7h9v5h-9"/><rect x="17" y="33" width="6" height="6" rx="1"/><rect x="41" y="33" width="6" height="6" rx="1"/>',
    'bus' => '<rect x="12" y="10" width="40" height="38" rx="7"/><path d="M12 30h40M12 21h40M18 48v6M46 48v6"/><circle cx="21" cy="39" r="2.5"/><circle cx="43" cy="39" r="2.5"/>',
    'meal' => '<circle cx="34" cy="34" r="16"/><circle cx="34" cy="34" r="9"/><path d="M10 10v14a4 4 0 0 0 8 0V10M14 10v44M56 10c-4 2-6 8-6 16h6V54"/>',
    'talk' => '<path d="M8 14h30v20H20l-8 7v-7H8zM42 24h14v18h-4v6l-7-6H30v-4"/>',
    'link' => '<path d="M27 37l10-10M23 30l-6 6a8 8 0 0 0 11 11l6-6M41 34l6-6a8 8 0 0 0-11-11l-6 6"/>',
    'works' => '<path d="M12 50h40M18 50V22l14-12 14 12v28M10 24l22-18 22 18M28 50V38h8v12"/><path d="M44 40l8 8"/>',
    'mail' => '<rect x="8" y="14" width="48" height="36" rx="5"/><path d="M9 17l23 19 23-19"/>',
    'news' => '<path d="M10 12h36v40H16a6 6 0 0 1-6-6zM46 22h8v24a6 6 0 0 1-12 0"/><path d="M17 21h22M17 29h22M17 37h14M17 45h10"/>',
    'doc' => '<path d="M16 8h22l10 10v38H16zM38 8v10h10M23 30h18M23 38h18M23 46h12"/>',
    'balloon' => '<ellipse cx="32" cy="24" rx="14" ry="17"/><path d="M29 44h6l-3-3zM32 44c-3 5 3 8 0 14"/>',
];
$icon = isset($icon, $paths[$icon]) ? $icon : 'balloon';
?>
<svg class="rub-icon" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><?= $paths[$icon] ?></svg>
