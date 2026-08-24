<?php
/*
|--------------------------------------------------------------------------
| Header Profile
|--------------------------------------------------------------------------
| Ito ang iisang profile sa header. Dito ang picture, name, role,
| dropdown, at links. Admin muna ang gumagamit nito ngayon.
|--------------------------------------------------------------------------
*/

$headerProfileRootAttr = (string)($headerProfileRootAttr ?? 'data-profile-root');
$headerProfileToggleId = (string)($headerProfileToggleId ?? 'topbarProfileToggle');
$headerProfileDropdownId = (string)($headerProfileDropdownId ?? 'topbarProfileDropdown');
$headerProfileToggleAttr = (string)($headerProfileToggleAttr ?? 'data-profile-toggle');
$headerProfileName = (string)($headerProfileName ?? 'User');
$headerProfileRole = (string)($headerProfileRole ?? '');
$headerProfilePhotoUrl = (string)($headerProfilePhotoUrl ?? '');
$headerProfileInitials = (string)($headerProfileInitials ?? 'U');
$headerProfileAlt = (string)($headerProfileAlt ?? 'Profile picture');
$headerProfileLinks = is_array($headerProfileLinks ?? null) ? $headerProfileLinks : [];
?>
<div class="topbar-profile" <?php echo $headerProfileRootAttr; ?>>
    <button
        title="Account"
        id="<?php echo htmlspecialchars($headerProfileToggleId, ENT_QUOTES, 'UTF-8'); ?>"
        class="topbar-profile__toggle"
        type="button"
        aria-label="Open profile menu"
        aria-controls="<?php echo htmlspecialchars($headerProfileDropdownId, ENT_QUOTES, 'UTF-8'); ?>"
        aria-expanded="false"
        <?php echo $headerProfileToggleAttr; ?>
    >
        <span class="topbar-profile__avatar-shell" aria-hidden="true">
            <?php if ($headerProfilePhotoUrl !== ''): ?>
                <img src="<?php echo htmlspecialchars($headerProfilePhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($headerProfileAlt, ENT_QUOTES, 'UTF-8'); ?>" class="topbar-profile__avatar-image">
            <?php endif; ?>
            <span class="topbar-profile__avatar-fallback"><?php echo htmlspecialchars($headerProfileInitials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="topbar-profile__chevron-badge">
                <span class="topbar-profile__chevron" aria-hidden="true">
                    <svg viewBox="0 0 20 20" focusable="false">
                        <path d="M5 7.5 10 12.5 15 7.5"></path>
                    </svg>
                </span>
            </span>
        </span>
    </button>

    <div id="<?php echo htmlspecialchars($headerProfileDropdownId, ENT_QUOTES, 'UTF-8'); ?>" class="topbar-profile__dropdown" hidden>
        <div class="topbar-profile__panel-head">
            <span class="topbar-profile__avatar-shell topbar-profile__avatar-shell--panel" aria-hidden="true">
                <?php if ($headerProfilePhotoUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($headerProfilePhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($headerProfileAlt, ENT_QUOTES, 'UTF-8'); ?>" class="topbar-profile__avatar-image topbar-profile__avatar-image--panel">
                <?php endif; ?>
                <span class="topbar-profile__avatar-fallback topbar-profile__avatar-fallback--panel"><?php echo htmlspecialchars($headerProfileInitials, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <div>
                <strong><?php echo htmlspecialchars($headerProfileName, ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($headerProfileRole !== ''): ?>
                    <span><?php echo htmlspecialchars($headerProfileRole, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="topbar-profile__links">
            <?php foreach ($headerProfileLinks as $link): ?>
                <?php
                $label = (string)($link['label'] ?? '');
                $href = (string)($link['href'] ?? '#');
                if ($label === '') {
                    continue;
                }
                ?>
                <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
