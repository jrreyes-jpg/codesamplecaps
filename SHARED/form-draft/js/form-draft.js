(function () {
    'use strict';

    window.SharedFormDraft = {
        read: function (key, options) {
            if (!key) return null;
            const settings = options || {};
            try {
                const raw = window.localStorage.getItem(key);
                if (!raw) return null;
                const draft = JSON.parse(raw);
                const savedAt = Number(draft?.saved_at || 0);
                const lifetime = Number(settings.lifetime || 0);
                const expired = lifetime > 0 && (!Number.isFinite(savedAt) || Date.now() - savedAt > lifetime);
                if (expired || (settings.validate && !settings.validate(draft))) {
                    window.localStorage.removeItem(key);
                    return null;
                }
                return draft;
            } catch (error) {
                return null;
            }
        },
        save: function (key, draft) {
            if (!key || !draft) return false;
            try {
                window.localStorage.setItem(key, JSON.stringify(draft));
                return true;
            } catch (error) {
                return false;
            }
        },
        remove: function (key) {
            if (!key) return;
            try {
                window.localStorage.removeItem(key);
            } catch (error) {
                // Tuloy lang kapag hindi available ang browser storage.
            }
        },
    };
}());
