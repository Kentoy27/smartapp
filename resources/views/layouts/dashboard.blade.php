<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f1f5f9">
    <link rel="icon" type="image/svg+xml" href="{{ public_asset('smap.svg') }}?v=2">
    <link rel="shortcut icon" href="{{ public_asset('smap.svg') }}?v=2">
    <link rel="stylesheet" href="{{ public_asset('css/dashboard.css') }}?v=1">
    <title>@yield('title', 'Dashboard — SmartApp')</title>
    <script>
        /* Apply the saved theme before first paint to avoid a flash.
           Reads localStorage with a cookie fallback (private mode and
           partitioned iframes can block localStorage). Default: light. */
        (function () {
            var theme = 'light';
            try {
                var saved = localStorage.getItem('smartapp-theme');
                if (saved === 'dark' || saved === 'light') theme = saved;
            } catch (e) {}
            if (theme === 'light') {
                var m = document.cookie.match(/(?:^|;\s*)smartapp-theme=(dark|light)/);
                if (m) theme = m[1];
            }
            document.documentElement.setAttribute('data-theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#f1f5f9');
        })();
    </script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* ---------- THEME VARIABLES ---------- */
        :root {
            color-scheme: light;
            --bg: #f1f5f9;
            --bg-panel: #ffffff;
            --bg-elevated: #ffffff;
            --bg-muted: #e2e8f0;
            --bg-hover: #e2e8f0;
            --bg-active: #dbeafe;
            --text: #1e293b;
            --text-strong: #0f172a;
            --text-muted: #64748b;
            --text-faint: #94a3b8;
            --border: #e2e8f0;
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-dropdown: 0 12px 28px rgba(0, 0, 0, 0.18);
            --primary: #3b82f6;
            --primary-strong: #1d4ed8;
            --primary-soft: #dbeafe;
            --danger: #b91c1c;
            --danger-soft: #fee2e2;
            --warn: #b45309;
            --warn-soft: #fef3c7;
            --ok: #047857;
            --ok-soft: #d1fae5;
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --bg: #0f172a;
            --bg-panel: #1e293b;
            --bg-elevated: #24334a;
            --bg-muted: #0f172a;
            --bg-hover: #273549;
            --bg-active: #273549;
            --text: #e2e8f0;
            --text-strong: #f8fafc;
            --text-muted: #94a3b8;
            --text-faint: #64748b;
            --border: #334155;
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4);
            --shadow-dropdown: 0 12px 28px rgba(0, 0, 0, 0.55);
            --primary: #3b82f6;
            --primary-strong: #2563eb;
            --primary-soft: #1e3a8a;
            --danger: #f87171;
            --danger-soft: #451a1a;
            --warn: #fbbf24;
            --warn-soft: #452503;
            --ok: #34d399;
            --ok-soft: #064e3b;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        body.no-scroll {
            overflow: hidden;
        }

        /* ---------- CONTENT AREA ---------- */
        .content-area {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }

        /* ---------- HEADER ---------- */
        .header {
            background: var(--bg-panel);
            color: var(--text-strong);
            padding: 0 24px;
            height: 60px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-shrink: 0;
            transition: padding 0.25s ease;
            position: relative;
            z-index: 100;
        }

        .header.collapsed {
            padding: 0 12px;
        }

        .header .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-left: auto;
        }

        .header .user-info .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8125rem;
            color: #fff;
        }

        .header .user-info .user-menu-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            padding: 4px 6px;
            margin-right: -6px;
            cursor: pointer;
            color: var(--text-faint);
            font: inherit;
            -webkit-tap-highlight-color: transparent;
            transition: color 0.15s;
        }

        .header .user-info .user-menu-trigger:hover,
        .header .user-info .user-menu-trigger.open {
            color: var(--text-strong);
        }

        .header .user-info .user-menu-trigger:focus {
            outline: none;
        }

        .header .user-info .user-menu-trigger:focus-visible {
            border-radius: 4px;
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .header .user-info .chevron {
            display: inline-flex;
            transition: transform 0.2s ease;
        }

        .user-menu-trigger.open .chevron {
            transform: rotate(180deg);
        }

        .user-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 230px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow-dropdown);
            padding: 6px;
            z-index: 200;
            display: none;
        }

        .user-dropdown.open {
            display: block;
            animation: dropdown-in 0.15s ease;
        }

        @keyframes dropdown-in {
            from { opacity: 0.85; transform: translateY(-4px); }
            to   { opacity: 1;    transform: translateY(0); }
        }

        .user-dropdown-header {
            display: flex;
            flex-direction: column;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 6px;
        }

        .user-dropdown-header strong {
            color: var(--text-strong);
            font-size: 0.875rem;
        }

        .user-dropdown-header span {
            color: var(--text-muted);
            font-size: 0.75rem;
            margin-top: 2px;
            word-break: break-all;
        }

        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 9px 12px;
            border-radius: 6px;
            font-size: 0.875rem;
            font-family: inherit;
            color: var(--danger);
            cursor: pointer;
            transition: background 0.15s;
        }

        .user-dropdown-item:hover {
            background: var(--danger-soft);
        }

        .header .user-info .name {
            font-size: 0.875rem;
            color: var(--text-faint);
        }

        .header .user-info .name strong {
            color: var(--text-strong);
            font-weight: 600;
        }

        /* ---------- LAYOUT ---------- */
        .layout {
            display: flex;
            flex: 1;
            min-height: 100vh;
        }

        /* ---------- SIDEBAR TOGGLE ---------- */
        .sidebar-toggle {
            background: none;
            border: none;
            color: var(--text-faint);
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s;
            flex-shrink: 0;
            -webkit-tap-highlight-color: transparent;
        }

        .sidebar-toggle:hover {
            color: var(--text-strong);
        }

        .sidebar-toggle:focus {
            outline: none;
        }

        .sidebar-toggle:focus-visible {
            border-radius: 4px;
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        /* Hamburger swaps to a close (X) icon while the mobile drawer is open */
        .sidebar-toggle .icon-close {
            display: none;
        }

        .sidebar-toggle.is-open .icon-menu {
            display: none;
        }

        .sidebar-toggle.is-open .icon-close {
            display: flex;
        }

        /* Smoothly spin the icon when the sidebar is collapsed to a rail */
        .sidebar-toggle .icon-menu {
            transition: transform 0.25s ease;
        }

        .layout.collapsed .sidebar-toggle .icon-menu {
            transform: rotate(180deg);
        }

        /* The header copy of the toggle is only needed on small screens,
           where the drawer (and its toggle) hides off-canvas */
        .header .sidebar-toggle {
            display: none;
        }

        /* ---------- SIDEBAR ---------- */
        .sidebar {
            width: 220px;
            background: var(--bg-panel);
            color: var(--text-faint);
            padding: 6px 0 16px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            transition: width 0.25s ease, overflow 0.25s ease, background 0.25s ease;
            overflow: visible;
            position: sticky;
            top: 0;
            height: 100vh;
            align-self: flex-start;
            border-right: 1px solid var(--border);
        }

        /* Brand row pinned to the top of the sidebar: SmartApp logo on the
           left, sidebar toggle on the right */
        .sidebar .sidebar-brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            padding: 0 12px;
            height: 58px;
        }

        .sidebar .sidebar-brand-row .brand {
            display: inline-flex;
            align-items: center;
            font-weight: 700;
            font-size: 1.35rem;
            letter-spacing: -0.03em;
            white-space: nowrap;
        }

        .sidebar .sidebar-brand-row .brand .brand-blue {
            color: var(--primary);
        }

        .sidebar .sidebar-brand-row .brand .brand-black {
            color: var(--text-strong);
        }

        .layout.collapsed .sidebar .sidebar-brand-row {
            justify-content: center;
            padding: 0;
            position: relative;
            isolation: isolate;
        }

        .layout.collapsed .sidebar .sidebar-brand-row .brand {
            display: none;
        }

        .layout.collapsed .sidebar .sidebar-brand-row .sidebar-toggle {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            color: var(--text-muted);
            background: var(--bg-panel);
            z-index: 1;
            pointer-events: auto;
        }

        .layout.collapsed .sidebar .sidebar-brand-row .sidebar-toggle svg {
            pointer-events: none;
        }

        .layout.collapsed .sidebar .sidebar-brand-row .sidebar-toggle:hover {
            color: var(--primary);
            background: var(--bg-hover);
        }

        .layout.collapsed .sidebar {
            width: 60px;
            overflow: hidden;
        }

        .layout.collapsed .sidebar .nav-item span:not(.icon),
        .layout.collapsed .sidebar .nav-section {
            display: none;
        }

        .layout.collapsed .sidebar .nav-item {
            justify-content: center;
            padding: 10px 0;
        }

        .layout.collapsed .sidebar .nav-item .icon {
            margin: 0;
        }

        .layout.collapsed .sidebar .nav-section {
            padding: 16px 0 6px;
            text-align: center;
        }

        .layout.collapsed .sidebar .nav-section span {
            display: none;
        }

        /* In collapsed-rail mode the wire:current active rule still applies; the
           icon is centered by the generic .collapsed .nav-item rule. */

        .layout.collapsed .content-area {
            margin-left: 0;
        }

        .sidebar .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            color: var(--text-faint);
            text-decoration: none;
            font-size: 0.9375rem;
            transition: background 0.15s, color 0.15s;
            border-left: 3px solid transparent;
            white-space: nowrap;
        }

        .sidebar .nav-item:hover {
            background: var(--bg-hover);
            color: var(--text-strong);
        }

        .sidebar .nav-item.active {
            background: var(--bg-active);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar .nav-item .icon {
            display: inline-flex;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* Live users-count badge on the sidebar's Users item */
        .sidebar .nav-item .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: #fff;
            font-size: 0.6875rem;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 999px;
            line-height: 1.4;
        }

        .layout.collapsed .sidebar .nav-item .nav-badge {
            display: none;
        }

        /* ---------- USERS PAGE (live table) ---------- */
        .users-toolbar {
            flex-wrap: wrap;
            /* Push Add User + Rows to the right edge, vertically centered
               with the search box */
            align-items: center;
        }

        .users-add-btn {
            margin-left: auto;
        }

        .users-search {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 320px;
        }

        .users-search input {
            width: 100%;
            padding: 8px 30px 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-elevated);
            color: var(--text);
            font: inherit;
            font-size: 0.875rem;
        }

        .users-search input:focus {
            outline: 2px solid var(--primary);
            outline-offset: -1px;
        }

        .users-clear {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: var(--bg-muted);
            color: var(--text-muted);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 0.875rem;
            line-height: 1;
        }

        .users-per-page {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        .users-per-page select {
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg-elevated);
            color: var(--text);
            font: inherit;
            padding: 6px 8px;
        }

        .data-table th.users-actions-col,
        .data-table td.users-actions-col {
            text-align: right;
            white-space: nowrap;
            width: 1%;
            padding-left: 18px;
        }

        /* Action buttons sit in one right-aligned row with consistent gaps */
        .users-actions-row {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            width: 100%;
            margin-left: auto;
        }

        .users-action {
            border: 1px solid var(--border);
            background: var(--bg-elevated);
            color: var(--text);
            font: inherit;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            /* Rendered on <button> and on <a> (the row actions) — an anchor
               would otherwise underline the label inside the chip. */
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .users-action-inner {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            line-height: 1;
        }

        .users-action-inner svg {
            flex-shrink: 0;
        }

        .users-action:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .users-action--danger:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        .users-action:disabled {
            opacity: 0.5;
            cursor: wait;
        }

        .users-footer {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 14px;
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        .users-count {
            margin-left: auto;
        }

        .users-live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            visibility: hidden;
        }

        .users-live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
            animation: live-pulse 1s ease-in-out infinite;
        }

        @keyframes live-pulse {
            0%, 100% { opacity: 0.35; }
            50% { opacity: 1; }
        }

        .users-pagination {
            display: flex;
            gap: 6px;
        }

        /* ---------- ADD USER BUTTON + MODAL ---------- */
        .users-add-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            font: inherit;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
        }

        .users-add-btn:hover {
            background: var(--primary-strong);
        }

        .users-add-btn:active {
            transform: scale(0.98);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 300;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-backdrop.is-open {
            display: flex;
            animation: backdrop-in 0.15s ease;
        }

        @keyframes backdrop-in {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .modal {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow-dropdown);
            width: 100%;
            max-width: 420px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modal-in 0.18s ease;
        }

        @keyframes modal-in {
            from { opacity: 0; transform: translateY(-10px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px 0;
        }

        .modal-head h2 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-strong);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-faint);
            font-size: 1.375rem;
            line-height: 1;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: color 0.15s, background 0.15s;
        }

        .modal-close:hover {
            color: var(--text-strong);
            background: var(--bg-hover);
        }

        .modal-form {
            padding: 18px 22px 22px;
        }

        .modal-form .field {
            margin-bottom: 14px;
        }

        .role-modal-description {
            color: var(--text-muted);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .role-modal-description strong {
            color: var(--text-strong);
        }

        .delete-modal-description {
            color: var(--text-muted);
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .delete-modal-description strong {
            color: var(--text-strong);
        }

        .btn-danger {
            padding: 9px 18px;
            border: 1px solid var(--danger);
            border-radius: 8px;
            background: var(--danger);
            color: #fff;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-danger:hover {
            filter: brightness(0.9);
        }

        .btn-danger:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .modal-form label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .modal-form input {
            width: 100%;
            padding: 9px 12px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            font-size: 16px; /* stops iOS auto-zoom on focus */
            font-family: inherit;
            transition: border-color 0.2s, background 0.2s;
        }

        .modal-form input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .modal-form input.error {
            border-color: #ef4444;
        }

        .modal-form select {
            width: 100%;
            padding: 9px 12px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            font-size: 0.875rem;
            font-family: inherit;
            transition: border-color 0.2s, background 0.2s;
        }

        .modal-form select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .modal-form select.error {
            border-color: #ef4444;
        }

        .error-text {
            color: var(--danger);
            font-size: 0.75rem;
            margin-top: 4px;
        }

        /* Password eye toggle inside the modal (same pattern as login) */
        .modal-form .password-wrap {
            position: relative;
        }

        .modal-form .password-wrap input {
            padding-right: 44px;
        }

        .modal-form .password-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            background: none;
            border: none;
            border-radius: 6px;
            color: var(--text-faint);
            cursor: pointer;
            transition: color 0.15s;
            touch-action: manipulation;
        }

        .modal-form .password-toggle:hover {
            color: var(--text-strong);
        }

        .modal-form .password-toggle .icon-eye {
            display: flex;
        }

        .modal-form .password-toggle .icon-eye-off {
            display: none;
        }

        .modal-form .password-toggle.revealed .icon-eye {
            display: none;
        }

        .modal-form .password-toggle.revealed .icon-eye-off {
            display: flex;
        }

        /* ---------- ROLE PICKER (add/edit user modal) ---------- */
        .role-picker {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        @media (max-width: 480px) {
            .role-picker {
                grid-template-columns: 1fr;
            }
        }

        .role-option {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }

        .role-option:hover {
            border-color: var(--text-faint);
        }

        .role-option:has(input:checked) {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        .role-option input[type="radio"] {
            width: auto;
            min-width: 0;
            margin-top: 3px;
            accent-color: var(--primary);
            box-shadow: none;
            outline: none;
            flex-shrink: 0;
        }

        .role-option-body {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .role-option-body strong {
            font-size: 0.8125rem;
            color: var(--text-strong);
        }

        .role-option-body small {
            font-size: 0.71875rem;
            color: var(--text-muted);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-ghost,
        .btn-primary {
            padding: 9px 18px;
            border-radius: 8px;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            /* The class is also used on <a> elements (the download actions):
               without this the browser underlines the label through the
               button's own background. */
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .btn-ghost {
            background: none;
            border: 1px solid var(--border);
            color: var(--text-muted);
        }

        .btn-ghost:hover {
            background: var(--bg-hover);
            color: var(--text-strong);
        }

        .btn-primary {
            background: var(--primary);
            border: 1px solid var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-strong);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        /* ---------- SUCCESS TOAST ---------- */
        [x-cloak] {
            display: none !important;
        }

        .success-alert-sentinel {
            display: none;
        }

        /* ---------- OPCR TEMPLATE (dashboard download card) ---------- */
        .opcr-template-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .opcr-file-badge {
            flex: none;
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .opcr-file-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
            flex: 1;
        }

        .opcr-file-name {
            font-weight: 600;
            color: var(--text-strong);
        }

        .opcr-file-sub {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        .opcr-download-btn {
            flex: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .opcr-download-btn:hover {
            background: var(--primary-strong);
            border-color: var(--primary-strong);
        }

        .opcr-download-btn:active {
            transform: translateY(1px);
        }

        /* ---------- LOCKED OPCR CARD (OPCRF + MOVs already submitted) ---------- */
        .opcr-card-locked {
            opacity: 0.92;
        }

        .opcr-file-badge--locked {
            background: var(--warn-soft);
            color: var(--warn);
        }

        .opcr-locked-pill {
            flex: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            background: var(--warn-soft);
            color: var(--warn);
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ---------- OPCR UPLOAD BUTTON (beside the card's download button) ---------- */
        .opcrf-upload-btn {
            flex: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1px solid var(--primary);
            background: transparent;
            color: var(--primary);
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }

        .opcrf-upload-btn:hover {
            background: var(--primary-soft);
        }

        .opcrf-upload-btn:active {
            transform: translateY(1px);
        }

        .opcrf-upload-btn:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .opcrf-upload-btn.has-error {
            border-color: #ef4444;
            color: #ef4444;
        }

        /* ---------- WFP (Work and Financial Plan) module ---------- */
        .wfp-status-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px 20px;
            margin: 16px 0 0;
            padding: 16px 0 0;
            border-top: 1px solid var(--border);
        }

        .wfp-status-item dt {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .wfp-status-item dd {
            margin: 0;
            font-size: 0.875rem;
            color: var(--text-strong);
        }

        .wfp-status-pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-strong);
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .wfp-empty {
            color: var(--text-muted);
            font-size: 0.875rem;
            line-height: 1.6;
            margin: 0;
        }

        .wfp-remove-btn {
            flex: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1px solid #dc2626;
            background: transparent;
            color: #dc2626;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .wfp-remove-btn:hover {
            background: rgba(220, 38, 38, 0.08);
        }

        .wfp-remove-btn:active {
            transform: translateY(1px);
        }

        .wfp-preview-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .wfp-search {
            flex: 1;
            min-width: 200px;
            padding: 9px 12px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            font: inherit;
            font-size: 0.875rem;
        }

        .wfp-search:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .wfp-preview-note {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        .wfp-preview-scroll {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
            -webkit-overflow-scrolling: touch;
        }

        .wfp-preview-table {
            border-collapse: collapse;
            width: 100%;
            font-size: 0.75rem;
            white-space: nowrap;
        }

        .wfp-preview-table th,
        .wfp-preview-table td {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            text-align: left;
            color: var(--text);
        }

        .wfp-preview-table thead th {
            background: var(--bg-elevated);
            color: var(--text-strong);
            font-weight: 600;
        }

        .wfp-preview-rowhead {
            position: sticky;
            left: 0;
            background: var(--bg-elevated);
            color: var(--text-muted);
            font-weight: 600;
            text-align: center;
        }

        /* ---------- OPCRF SUBMISSION FORM (staff /opcrf page) ---------- */
        .opcrf-card-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .opcrf-form {
            margin-top: 14px;
        }

        .opcrf-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 16px;
        }

        .opcrf-grid .field {
            margin-bottom: 0;
        }

        .opcrf-grid .opcrf-field--full {
            grid-column: 1 / -1;
        }

        @media (max-width: 640px) {
            .opcrf-grid {
                grid-template-columns: 1fr;
            }
        }

        .opcrf-grid input,
        .opcrf-grid textarea {
            width: 100%;
            padding: 9px 12px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            font: inherit;
            font-size: 0.875rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .opcrf-grid textarea {
            resize: vertical;
            min-height: 90px;
            line-height: 1.5;
        }

        .opcrf-grid input:focus,
        .opcrf-grid textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .opcrf-grid input.error,
        .opcrf-grid textarea.error {
            border-color: #ef4444;
        }

        .opcrf-grid label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .opcrf-optional {
            font-weight: 400;
            color: var(--text-faint);
        }

        .opcrf-rating-scale {
            display: block;
            font-size: 0.71875rem;
            color: var(--text-faint);
            margin-top: 5px;
            line-height: 1.5;
        }

        .opcrf-form-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            margin-top: 18px;
            flex-wrap: wrap;
        }

        .opcrf-review-hint {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            color: var(--text-faint);
            margin-right: auto;
        }

        .opcrf-submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: 1px solid var(--primary);
            border-radius: 8px;
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .opcrf-submit-btn:hover {
            background: var(--primary-strong);
            border-color: var(--primary-strong);
        }

        .opcrf-submit-btn:active {
            transform: translateY(1px);
        }

        .opcrf-submit-btn:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        /* ---------- OPCRF REVIEW MODAL (pops before final submit) ---------- */
        .modal--opcrf-review {
            max-width: 640px;
        }

        .modal--opcr-upload {
            max-width: 520px;
        }

        .opcrf-upload-body {
            padding-top: 14px;
        }

        .opcrf-review-note {
            color: var(--text-muted);
            font-size: 0.8125rem;
            line-height: 1.5;
            margin: 10px 22px 0;
        }

        .opcrf-review-body {
            padding-top: 14px;
        }

        .opcrf-review-summary {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            background: var(--bg-elevated);
        }

        .opcrf-review-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
        }

        @media (max-width: 560px) {
            .opcrf-review-grid {
                grid-template-columns: 1fr;
            }
        }

        .opcrf-review-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .opcrf-review-item--full {
            grid-column: 1 / -1;
        }

        .opcrf-review-label {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-faint);
        }

        .opcrf-review-value {
            font-size: 0.875rem;
            color: var(--text-strong);
            word-break: break-word;
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            flex-wrap: wrap;
        }

        .opcrf-review-value--pre {
            white-space: pre-line;
            line-height: 1.55;
        }

        .opcrf-review-rating-word {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .opcrf-review-confirm-line {
            margin: 14px 0 0;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .opcrf-review-confirm-line strong {
            color: var(--text-strong);
        }

        /* ---------- EXCEL-STYLE SHEET (uploaded OPCRF review modal) ----------
           Mirrors the real Excel template's design: navy title banner,
           header block rows, bordered objectives table with criteria /
           accomplishments / rating columns. */
        .modal--opcrf-sheet {
            max-width: 860px;
        }

        .opcrf-review-summary--sheet {
            padding: 0;
            overflow: hidden;
        }

        .opcrf-sheet {
            font-size: 0.75rem;
            line-height: 1.45;
        }

        .opcrf-sheet-banner {
            background: #1f3864;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.8125rem;
            letter-spacing: 0.02em;
            text-align: center;
            padding: 12px 16px;
        }

        .opcrf-sheet-headerblock {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            display: grid;
            gap: 0;
        }

        /* Side-by-side header: staff details (left) next to the evaluator
           block (right) — mirroring the template's own two-block layout. */
        .opcrf-sheet-headerblock--split {
            grid-template-columns: 1.6fr 1fr;
            gap: 0 24px;
        }

        @media (max-width: 700px) {
            .opcrf-sheet-headerblock--split {
                grid-template-columns: 1fr;
                gap: 10px 0;
            }

            .opcrf-sheet-headercol--evaluator {
                border-top: 1px dashed var(--border);
                padding-top: 6px;
            }
        }

        .opcrf-sheet-headercol {
            min-width: 0;
        }

        .opcrf-sheet-headrow {
            display: grid;
            grid-template-columns: minmax(150px, 240px) 1fr;
            gap: 4px 12px;
            padding: 5px 0;
            border-bottom: 1px dashed var(--border);
            align-items: baseline;
        }

        .opcrf-sheet-headrow:last-child {
            border-bottom: none;
        }

        /* Evaluator block: its labels are short captions ("Evaluator:",
           "Position:", "Approving Authority:"), so each row stacks —
           label as a small caption above the value. Side-by-side here
           squeezed the value column to a sliver and the names wrapped
           letter by letter ("DOLL / OSA"). */
        .opcrf-sheet-headercol--evaluator .opcrf-sheet-headrow {
            grid-template-columns: 1fr;
            gap: 2px;
            padding: 7px 0;
        }

        .opcrf-sheet-headercol--evaluator .opcrf-sheet-headlabel {
            color: #5a3e1f;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .opcrf-sheet-headercol--evaluator .opcrf-sheet-headvalue {
            word-break: normal;
            overflow-wrap: break-word;
        }

        .opcrf-sheet-headlabel {
            font-weight: 700;
            color: #1f3864;
            word-break: break-word;
        }

        .opcrf-sheet-headvalue {
            color: var(--text-strong);
            word-break: break-word;
        }

        .opcrf-sheet-headvalue.is-empty {
            color: var(--text-faint);
            font-style: italic;
        }

        .opcrf-sheet-part {
            padding-top: 6px;
        }

        .opcrf-sheet-partbanner {
            display: flex;
            align-items: baseline;
            gap: 10px;
            background: #1f3864;
            color: #ffffff;
            padding: 8px 12px;
        }

        .opcrf-sheet-partkey {
            font-weight: 700;
            font-size: 0.75rem;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .opcrf-sheet-parttitle {
            font-weight: 700;
            font-size: 0.78rem;
        }

        .opcrf-sheet-partnote {
            font-size: 0.6875rem;
            color: var(--text-faint);
            padding: 6px 12px;
            border-bottom: 1px solid var(--border);
        }

        .opcrf-sheet-totalrow td {
            font-weight: 700;
            background: var(--bg-muted);
        }

        .opcrf-sheet-signers {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }

        .opcrf-sheet-signers-head {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-faint);
            margin-bottom: 10px;
        }

        .opcrf-sheet-signers-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        @media (max-width: 700px) {
            .opcrf-sheet-signers-row {
                grid-template-columns: 1fr;
            }
        }

        .opcrf-sheet-signer {
            display: grid;
            gap: 3px;
            border-top: 1px solid var(--border);
            padding-top: 8px;
        }

        .opcrf-sheet-signer-name {
            font-weight: 700;
            color: var(--text-strong);
            word-break: break-word;
        }

        .opcrf-sheet-signer-name.is-empty {
            color: var(--text-faint);
            font-style: italic;
            font-weight: 400;
        }

        .opcrf-sheet-signer-role {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #1f3864;
        }

        .opcrf-sheet-tablewrap {
            overflow-x: auto;
        }

        .opcrf-sheet-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .opcrf-sheet-table th {
            background: var(--bg-muted);
            color: var(--text-strong);
            font-weight: 700;
            text-align: left;
            vertical-align: bottom;
            padding: 8px;
            border: 1px solid var(--border);
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .opcrf-sheet-th-sub {
            display: block;
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
            color: var(--text-muted);
            font-size: 0.625rem;
        }

        .opcrf-sheet-table td {
            border: 1px solid var(--border);
            padding: 8px;
            vertical-align: top;
            word-break: break-word;
            color: var(--text);
        }

        .opcrf-sheet-num {
            width: 34px;
            text-align: center !important;
            font-weight: 700;
            color: var(--text-muted) !important;
        }

        .opcrf-sheet-obj {
            width: 22%;
        }

        .opcrf-sheet-time {
            width: 12%;
        }

        .opcrf-sheet-crit {
            width: 15%;
        }

        .opcrf-sheet-acc {
            width: 33%;
        }

        .opcrf-sheet-rate {
            width: 8%;
            text-align: center !important;
            font-weight: 700;
            white-space: nowrap;
        }

        .opcrf-sheet-table tfoot td {
            background: var(--bg-muted);
            font-weight: 700;
            color: var(--text-strong);
        }

        .opcrf-sheet-table tfoot .opcrf-sheet-rate {
            font-size: 0.875rem;
        }

        .opcrf-sheet-remarks {
            border-top: 1px solid var(--border);
            padding: 10px 16px 14px;
        }

        .opcrf-sheet-empty {
            text-align: center;
            color: var(--text-faint);
            font-style: italic;
            padding: 20px 8px !important;
        }

        .opcrf-review-summary--sheet .opcrf-review-confirm-line {
            margin: 14px 16px 0;
            padding-bottom: 14px;
        }

        .opcrf-sheet-errors {
            margin-top: 12px;
            padding: 10px 14px;
            border: 1px solid var(--danger);
            border-radius: 8px;
            background: var(--danger-soft);
            color: var(--danger);
            font-size: 0.8125rem;
        }

        .opcrf-sheet-errors ul {
            margin: 6px 0 0 18px;
        }

        @media (max-width: 640px) {
            .opcrf-sheet-headrow {
                grid-template-columns: 1fr;
            }

            .opcrf-sheet-banner {
                font-size: 0.75rem;
            }
        }

        .opcrf-remarks-cell {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ---------- OPCR UPLOAD (dashboard dropzone card) ---------- */
        .opcrf-upload-form {
            margin-top: 14px;
        }

        .opcrf-file-input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            overflow: hidden;
            clip: rect(0 0 0 0);
        }

        .opcrf-dropzone {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 26px 16px;
            border: 1.5px dashed var(--border);
            border-radius: 10px;
            background: var(--bg-elevated);
            cursor: pointer;
            text-align: center;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .opcrf-dropzone:hover,
        .opcrf-dropzone:focus-within {
            border-color: var(--primary);
            background: var(--bg-hover);
        }

        .opcrf-dropzone.has-error {
            border-color: #ef4444;
        }

        .opcrf-dropzone:focus-within {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .opcrf-dropzone-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .opcrf-dropzone-badge {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .opcrf-dropzone-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-strong);
        }

        .opcrf-dropzone-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* ---------- MOVS MANAGER MODAL (dashboard submissions table) ---------- */
        .modal--movs {
            max-width: 560px;
        }

        .opcrf-movs-body {
            padding-top: 14px;
        }

        .opcrf-dropzone--compact {
            padding: 16px;
        }

        .opcrf-dropzone--compact .opcrf-dropzone-badge {
            width: 32px;
            height: 32px;
        }

        .opcrf-dropzone--compact .opcrf-dropzone-title {
            font-size: 0.8125rem;
        }

        .opcrf-movs-queue,
        .opcrf-movs-attached {
            margin-top: 14px;
        }

        .opcrf-movs-queue-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .btn-primary--small {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        .opcrf-movs-attached-head {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-faint);
            margin-bottom: 8px;
        }

        .opcrf-movs-list {
            list-style: none;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        .opcrf-movs-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            background: var(--bg-elevated);
            border-bottom: 1px solid var(--border);
            font-size: 0.8125rem;
        }

        .opcrf-movs-item:last-child {
            border-bottom: none;
        }

        .opcrf-movs-item-icon {
            flex: none;
            color: var(--text-faint);
        }

        .opcrf-movs-item-name {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-strong);
            text-decoration: none;
        }

        a.opcrf-movs-item-name:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .opcrf-movs-item-size {
            flex: none;
            font-size: 0.71875rem;
            color: var(--text-faint);
        }

        .users-action--tiny {
            padding: 4px 7px;
        }

        .opcrf-movs-empty {
            color: var(--text-faint);
            font-size: 0.8125rem;
            padding: 10px 0;
        }

        /* ---------- LOCKED MOVS WINDOW (OpcrfUpload flow) ---------- */
        .opcrf-locked-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--warn-soft);
            color: var(--warn);
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .opcrf-locked-note {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border: 1px solid var(--warn);
            border-radius: 8px;
            background: var(--warn-soft);
            color: var(--warn);
            font-size: 0.78125rem;
            margin-bottom: 12px;
        }

        .opcrf-locked-note svg {
            flex: none;
        }

        .btn-primary.opcrf-waiting-btn {
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* ---------- OPCR REVIEW (superadmin) ---------- */
        .opcrf-review-owner {
            display: block;
            font-size: 0.71875rem;
            color: var(--text-faint);
        }

        .opcrf-review-toolbar {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding-bottom: 10px;
        }

        /* Recipient picker in the upload review modal (who reviews it) */
        .opcrf-review-route {
            margin-top: 14px;
        }

        .opcrf-review-route-sub {
            color: var(--text-faint);
            font-size: 0.71875rem;
            margin-top: 6px;
        }

        .opcrf-review-route-empty {
            padding: 9px 12px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            background: var(--bg-elevated);
            color: var(--text-muted);
            font-size: 0.78125rem;
        }

        /* File loader (approval upload) — a button in the same geometry as
           the modal's primary button, deliberately a softer fill so that one
           stays the primary action. */
        .opcrf-file-load {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .opcrf-file-load-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border: 1px dashed var(--primary);
            border-radius: 8px;
            background: var(--primary-soft);
            color: var(--primary-strong);
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .opcrf-file-load-btn:hover {
            border-color: var(--primary);
            filter: brightness(0.97);
        }

        .opcrf-file-load-btn:focus-within {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .opcrf-file-load-btn.has-error {
            border-color: #ef4444;
            background: var(--danger-soft);
            color: var(--danger);
        }

        .opcrf-file-load-name {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            color: var(--text-strong);
            word-break: break-all;
        }

        .opcrf-file-load-name svg {
            flex: none;
            color: var(--text-faint);
        }

        .opcrf-file-load-hint {
            font-size: 0.75rem;
            color: var(--text-faint);
        }

        /* No original document on file (a legacy submission): the action
           button is replaced by an explanation, never a synthesized file. */
        .opcrf-review-nofile {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            max-width: 100%;
            padding: 7px 10px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            background: var(--bg-elevated);
            color: var(--text-muted);
            font-size: 0.75rem;
            line-height: 1.4;
            text-align: left;
        }

        .opcrf-review-nofile svg {
            flex: none;
            color: var(--text-faint);
        }

        .opcrf-review-sheetblock {
            margin-bottom: 14px;
        }

        /* ---------- APPROVAL PANEL (superadmin review outcome) ---------- */
        .opcrf-approval {
            margin-top: 14px;
        }

        .opcrf-approval-state {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-elevated);
            color: var(--text-muted);
            font-size: 0.78125rem;
            margin-bottom: 12px;
        }

        .opcrf-approval-state svg {
            flex: none;
        }

        .opcrf-approval-state.is-approved {
            border-color: var(--ok);
            background: var(--ok-soft);
            color: var(--ok);
        }

        .opcrf-approval-picked {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 0.78125rem;
            color: var(--text-strong);
        }

        .opcrf-approval-picked svg {
            flex: none;
            color: var(--text-faint);
        }

        .opcrf-approval-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 12px;
        }

        /* ---------- DISTRICT MANAGER (superadmin dashboard modal) ---------- */
        .district-view-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 14px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font: inherit;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }

        .district-view-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .modal--district {
            width: 100%;
            max-width: 680px;
            max-height: min(88vh, 760px);
            display: flex;
            flex-direction: column;
        }

        .district-modal-body {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 4px 2px 2px;
            overflow-y: auto;
        }

        .district-add-form {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .district-add-form .field {
            flex: 1;
            margin-bottom: 0;
        }

        .district-add-form input {
            width: 100%;
            padding: 9px 12px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            font: inherit;
            font-size: 0.875rem;
        }

        .district-add-form input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .district-add-form input.error,
        .school-add-form input.error {
            border-color: var(--danger);
        }

        /* Two-level table: district rows carry a tinted background and bold
           name; school rows are indented child rows. */
        .district-table-wrap {
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: auto;
            max-height: min(52vh, 460px);
        }

        .district-table {
            width: 100%;
            border-collapse: collapse;
        }

        .district-table th {
            position: sticky;
            top: 0;
            background: var(--bg-panel);
            z-index: 1;
        }

        .district-table .district-type-col {
            width: 90px;
            white-space: nowrap;
        }

        .district-table .district-actions-col {
            width: 250px;
            white-space: nowrap;
        }

        .district-row-row td {
            background: var(--accent-soft);
            border-top: 1px solid var(--border);
        }

        .district-row-row .cell-strong {
            display: block;
            font-size: 0.875rem;
        }

        .district-row-row .cell-sub {
            display: block;
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 400;
            margin-top: 1px;
        }

        .school-row-row td {
            border-top: 1px solid var(--border);
        }

        .school-cell {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8125rem;
            color: var(--text);
            padding-left: 18px;
        }

        .school-dot {
            color: var(--text-faint);
            flex-shrink: 0;
        }

        .district-actions-row {
            display: inline-flex;
            gap: 6px;
        }

        .district-action {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 9px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 7px;
            color: var(--text-muted);
            font: inherit;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s;
        }

        .district-action:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .district-action--danger:hover {
            color: var(--danger);
            border-color: var(--danger);
        }

        .rename-form {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .rename-form input {
            flex: 1;
            min-width: 140px;
            padding: 6px 10px;
            background: var(--bg-input);
            border: 1px solid var(--primary);
            border-radius: 7px;
            color: var(--text-strong);
            font: inherit;
            font-size: 0.8125rem;
        }

        .rename-form input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .rename-form input.error {
            border-color: var(--danger);
        }

        .rename-save,
        .rename-cancel {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            border: 1px solid var(--border);
            background: var(--bg-panel);
            font-size: 0.875rem;
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.15s, border-color 0.15s;
        }

        .rename-save:hover {
            color: #16a34a;
            border-color: #16a34a;
        }

        .rename-cancel:hover {
            color: var(--danger);
            border-color: var(--danger);
        }

        .rename-form .error-text {
            flex-basis: 100%;
        }

        .school-add-form {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            width: 100%;
        }

        .school-add-form .school-cell {
            flex: 1;
            min-width: 180px;
            padding-left: 18px;
        }

        .school-add-form input {
            width: 100%;
            padding: 7px 10px;
            background: var(--bg-input);
            border: 1px solid var(--primary);
            border-radius: 7px;
            color: var(--text-strong);
            font: inherit;
            font-size: 0.8125rem;
        }

        .school-add-form input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .school-add-form input.error {
            border-color: var(--danger);
        }

        .school-add-form .error-text {
            flex-basis: 100%;
            margin: 0;
        }

        .district-empty {
            text-align: center;
            color: var(--text-faint);
            font-size: 0.875rem;
            padding: 18px 0;
        }
        .users-page-btn {
            min-width: 28px;
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg-elevated);
            color: var(--text);
            font: inherit;
            font-size: 0.8125rem;
            cursor: pointer;
            text-align: center;
        }

        .users-page-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .users-page-btn.is-current {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            cursor: default;
        }

        .users-page-btn.is-disabled {
            opacity: 0.45;
            cursor: default;
        }

        .sidebar .nav-section {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-faint);
            padding: 8px 14px 6px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* ---------- MAIN CONTENT ---------- */
        .main {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
        }

        .main .page-header {
            margin-bottom: 24px;
        }

        .main .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-strong);
        }

        .main .page-header p {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 4px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-panel);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
        }

        .stat-card .stat-label {
            font-size: 0.8125rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-strong);
            margin-top: 6px;
        }

        .stat-card .stat-sub {
            font-size: 0.75rem;
            color: var(--text-faint);
            margin-top: 4px;
        }

        .card {
            background: var(--bg-panel);
            border-radius: 10px;
            padding: 24px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            margin-bottom: 16px;
        }

        .card .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-strong);
            margin-bottom: 12px;
        }

        .card .card-text {
            color: var(--text-muted);
            font-size: 0.875rem;
            line-height: 1.6;
        }

        .badge {
            display: inline-block;
            background: var(--primary-soft);
            color: var(--primary-strong);
            font-size: 0.6875rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-muted {
            background: var(--bg-muted);
            color: var(--text-muted);
        }

        /* Approved marker (superadmin review outcome) */
        .badge--ok {
            background: var(--ok-soft);
            color: var(--ok);
        }

        /* Stat values holding text (e.g. an email) rather than a number */
        .stat-card .stat-value--text {
            font-size: 1rem;
            word-break: break-all;
        }

        /* ---------- QUICK ACTIONS ---------- */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
        }

        /* Lets the logout <button> sit directly in the tile grid */
        .action-form {
            display: contents;
        }

        .action-tile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            text-align: left;
            text-decoration: none;
            width: 100%;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .action-tile:hover {
            background: var(--bg-hover);
            border-color: var(--primary);
            color: var(--text-strong);
        }

        .action-tile:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .action-tile .icon {
            display: inline-flex;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            color: var(--primary);
        }

        .action-tile .action-label {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .action-tile .action-label small {
            font-size: 0.75rem;
            font-weight: 400;
            color: var(--text-muted);
        }

        .action-tile.danger .icon {
            color: var(--danger);
        }

        .action-tile.danger:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        /* ---------- DATA TABLE ---------- */
        .table-wrap {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .data-table th {
            padding: 0 12px 10px;
            border-bottom: 1px solid var(--border);
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            text-align: left;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: var(--bg-hover);
        }

        .data-table .cell-strong {
            color: var(--text-strong);
            font-weight: 600;
        }

        .data-table .cell-sub {
            display: block;
            margin-top: 2px;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .table-empty {
            padding: 16px 12px;
            text-align: center;
            color: var(--text-muted);
        }

        /* Card header row: title on the left, action link on the right */
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .card-head .card-title {
            margin-bottom: 0;
        }

        .card-link {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--primary);
            text-decoration: none;
        }

        .card-link:hover {
            text-decoration: underline;
        }

        /* ---------- FOOTER ---------- */
        .footer {
            background: var(--bg-panel);
            color: var(--text-muted);
            text-align: center;
            padding: 12px;
            font-size: 0.8125rem;
            flex-shrink: 0;
            border-top: 1px solid var(--border);
        }

        /* ---------- MOBILE SIDEBAR BACKDROP ---------- */
        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            /* Above the header (z-index 100) so the dimmed layer covers the
               whole page, but below the drawer itself */
            z-index: 105;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }

        /* ---------- THEME TOGGLE ---------- */
        .theme-toggle {
            background: none;
            border: none;
            color: var(--text-faint);
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: color 0.15s, background 0.15s, transform 0.3s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .theme-toggle:hover {
            color: var(--text-strong);
            background: var(--bg-hover);
        }

        .theme-toggle:focus {
            outline: none;
        }

        .theme-toggle:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .theme-toggle .icon-sun {
            display: none;
        }

        .theme-toggle .icon-moon {
            display: flex;
        }

        [data-theme="dark"] .theme-toggle .icon-sun {
            display: flex;
        }

        [data-theme="dark"] .theme-toggle .icon-moon {
            display: none;
        }

        .theme-toggle {
            /* Kill the double-tap-zoom delay so every tap fires a click
               immediately (fixes needing extra taps on touch devices) */
            touch-action: manipulation;
            -webkit-user-select: none;
            user-select: none;
        }

        .theme-toggle:active {
            transform: rotate(30deg);
        }

        /* Smooth whole-page color transition while switching themes.
           Applied to <html> for ~400ms by the toggle script. */
        .theme-switching,
        .theme-switching *,
        .theme-switching *::before,
        .theme-switching *::after {
            transition:
                background-color 0.35s ease,
                color 0.35s ease,
                border-color 0.35s ease,
                box-shadow 0.35s ease,
                fill 0.35s ease,
                stroke 0.35s ease !important;
        }

        @media (prefers-reduced-motion: reduce) {
            .theme-switching,
            .theme-switching *,
            .theme-switching *::before,
            .theme-switching *::after {
                transition: none !important;
            }
        }

        /* Pop animation for the freshly-shown icon after a switch */
        @keyframes theme-icon-in {
            from { transform: rotate(-90deg) scale(0.4); opacity: 0; }
            to   { transform: rotate(0deg) scale(1);    opacity: 1; }
        }

        .theme-toggle.spun svg {
            animation: theme-icon-in 0.35s ease;
        }

        .sidebar-backdrop.visible {
            opacity: 1;
            visibility: visible;
        }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 768px) {
            .main { padding: 16px; }

            /* Header shows the user info on the right. The hamburger toggle
               lives here on small screens because the drawer (which has its
               own toggle next to the logo) is hidden off-canvas until opened */
            .header {
                padding: 0 12px;
            }

            .header .sidebar-toggle {
                display: flex;
                /* Hamburger sits on the LEFT of the header on mobile only */
                order: -1;
                margin-right: 12px;
            }

            /* Sidebar becomes an off-canvas drawer. z-index must sit above
               the header (100) and the backdrop (105), otherwise the header
               covers the drawer's top strip and the SmartApp logo never
               shows when it slides in. */
            .sidebar {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                z-index: 110;
                width: 220px;
                max-width: 82vw;
                height: auto;
                padding-top: 12px;
                overflow-y: auto;
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                box-shadow: none;
                align-self: stretch;
            }

            .layout.sidebar-open .sidebar {
                transform: translateX(0);
                box-shadow: 4px 0 16px rgba(0, 0, 0, 0.35);
            }

            /* Desktop collapse rules must not affect the drawer */
            .layout.collapsed .sidebar {
                width: 220px;
                overflow-y: auto;
            }

            .layout.collapsed .sidebar .sidebar-brand-row {
                justify-content: space-between;
                padding: 0 12px;
            }

            .layout.collapsed .sidebar .sidebar-brand-row .brand {
                display: inline-flex;
            }

            .layout.collapsed .sidebar .sidebar-brand-row .brand > span:last-child {
                display: inline;
            }

            .layout.collapsed .sidebar .sidebar-brand-row .sidebar-toggle {
                position: static;
                width: auto;
                height: auto;
                background: none;
            }
        }

        @media (max-width: 480px) {
            .header { padding: 0 10px; }
            .header .user-info { gap: 10px; }
            .header .user-info .name { display: none; }
        }
    </style>
</head>
<body>
    <!-- LAYOUT: SIDEBAR + CONTENT AREA (HEADER + MAIN) -->
    <div class="layout" id="layout">
        <!-- SIDEBAR (live: Livewire component, collapses to Dashboard + Users) -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand-row">
                <div class="brand">
                    <span class="brand-blue">Smart</span><span class="brand-black">App</span>
                </div>
                <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar" aria-label="Toggle sidebar" aria-expanded="false">
                    <x-icon name="menu" :size="20" class="icon-menu" />
                    <x-icon name="x" :size="20" class="icon-close" />
                </button>
            </div>
            <livewire:sidebar />
        </aside>

        <!-- CONTENT AREA: HEADER + MAIN + FOOTER -->
        <div class="content-area">
            <!-- HEADER -->
            <header class="header" id="header">
                <div class="user-info">
                    <button type="button" class="theme-toggle" id="themeToggle" title="Toggle light/dark mode" aria-label="Toggle light/dark mode">
                        <x-icon name="sun" :size="20" class="icon-sun" />
                        <x-icon name="moon" :size="20" class="icon-moon" />
                    </button>
                    <button type="button" class="user-menu-trigger" id="userMenuTrigger" aria-haspopup="true" aria-expanded="false" title="Account menu">
                        <div class="avatar">{{ strtoupper(substr(Auth::user()->username, 0, 2)) }}</div>
                        <span class="name"><strong>{{ Auth::user()->username }}</strong></span>
                        <span class="chevron" aria-hidden="true"><x-icon name="chevron-down" :size="14" /></span>
                    </button>

                    <div class="user-dropdown" id="userDropdown" role="menu">
                        <div class="user-dropdown-header">
                            <strong>{{ Auth::user()->username }}</strong>
                            @if(Auth::user()->email)
                                <span>{{ Auth::user()->email }}</span>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="user-dropdown-item" role="menuitem">
                                <x-icon name="log-out" :size="16" /> Logout
                            </button>
                        </form>
                    </div>
                </div>
                <button type="button" class="sidebar-toggle" id="sidebarToggleMobile" title="Toggle sidebar" aria-label="Toggle sidebar" aria-expanded="false">
                    <x-icon name="menu" :size="20" class="icon-menu" />
                    <x-icon name="x" :size="20" class="icon-close" />
                </button>
            </header>

            <!-- MAIN CONTENT -->
            <main class="main">
                {{ $slot ?? '' }}
                @yield('content')
            </main>

            <!-- FOOTER -->
            <footer class="footer">
                &copy; {{ date('Y') }} SmartApp &mdash; All rights reserved
            </footer>
        </div>
    </div>

    <script>
        /* Layout chrome (sidebar toggle, user menu, theme toggle) uses plain
           DOM listeners bound to element ids. Under Livewire's wire:navigate
           SPA mode the body content is swapped on every navigation, so this
           script must re-run per page. The IIFEs become named initializers
           that run on load AND on `livewire:navigated`, with per-element
           bind guards (dataset.*) so listeners are never attached twice to
           the same DOM node. */
        (function() {
            function initLayoutChrome() {
                var layout = document.getElementById('layout');
                if (!layout || layout.dataset.chromeBound) return; // this DOM already bound
                layout.dataset.chromeBound = '1';

                var sidebarToggles = Array.prototype.slice.call(document.querySelectorAll('.sidebar-toggle'));
                var mobileQuery = window.matchMedia('(max-width: 768px)');
                var collapsed = sessionStorage.getItem('sidebarCollapsed') === 'true';
                var mobileOpen = false;

                var backdrop = document.querySelector('.sidebar-backdrop');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.className = 'sidebar-backdrop';
                    document.body.appendChild(backdrop);
                }

                function isMobile() { return mobileQuery.matches; }

                function applyState() {
                    if (isMobile()) {
                        layout.classList.remove('collapsed');
                        layout.classList.toggle('sidebar-open', mobileOpen);
                    } else {
                        layout.classList.remove('sidebar-open');
                        layout.classList.toggle('collapsed', collapsed);
                    }
                    backdrop.classList.toggle('visible', isMobile() && mobileOpen);
                    document.body.classList.toggle('no-scroll', isMobile() && mobileOpen);
                    sidebarToggles.forEach(function(toggle) {
                        toggle.classList.toggle('is-open', isMobile() && mobileOpen);
                        toggle.setAttribute('aria-expanded', isMobile() ? String(mobileOpen) : String(!collapsed));
                    });
                }

                sidebarToggles.forEach(function(toggle) {
                    toggle.addEventListener('click', function() {
                        if (isMobile()) {
                            mobileOpen = !mobileOpen;
                        } else {
                            collapsed = !collapsed;
                            sessionStorage.setItem('sidebarCollapsed', collapsed);
                        }
                        applyState();
                    });
                });

                backdrop.addEventListener('click', function() {
                    mobileOpen = false;
                    applyState();
                });

                function onViewportChange() {
                    mobileOpen = false;
                    applyState();
                }

                if (mobileQuery.addEventListener) {
                    mobileQuery.addEventListener('change', onViewportChange);
                } else {
                    mobileQuery.addListener(onViewportChange);
                }

                applyState();
            }

            function initUserMenu() {
                var trigger = document.getElementById('userMenuTrigger');
                var dropdown = document.getElementById('userDropdown');
                if (!trigger || !dropdown || trigger.dataset.menuBound) return;
                trigger.dataset.menuBound = '1';

                function close() {
                    dropdown.classList.remove('open');
                    trigger.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                }

                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var willOpen = !dropdown.classList.contains('open');
                    dropdown.classList.toggle('open', willOpen);
                    trigger.classList.toggle('open', willOpen);
                    trigger.setAttribute('aria-expanded', String(willOpen));
                });

                document.addEventListener('click', function(e) {
                    if (!dropdown.isConnected) return; // DOM was swapped by navigate
                    if (!dropdown.contains(e.target) && !trigger.contains(e.target)) {
                        close();
                    }
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        close();
                    }
                });
            }

            function initThemeToggle() {
                var KEY = 'smartapp-theme';
                var root = document.documentElement;
                var toggle = document.getElementById('themeToggle');
                if (!toggle || toggle.dataset.themeBound) return;
                toggle.dataset.themeBound = '1';
                var meta = document.querySelector('meta[name="theme-color"]');
                var resetTimer = null;

                function readStoredTheme() {
                    var theme = 'light';
                    try {
                        var saved = localStorage.getItem(KEY);
                        if (saved === 'dark' || saved === 'light') return saved;
                    } catch (e) {}
                    try {
                        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + KEY + '=([^;]+)'));
                        if (match && (match[1] === 'dark' || match[1] === 'light')) return match[1];
                    } catch (e) {}
                    return theme;
                }

                function persist(theme) {
                    try { localStorage.setItem(KEY, theme); } catch (e) {}
                    document.cookie = KEY + '=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
                }

                function apply(theme, animate) {
                    root.setAttribute('data-theme', theme);
                    persist(theme);
                    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#f1f5f9');
                    toggle.setAttribute('aria-pressed', String(theme === 'dark'));
                    var label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
                    toggle.title = label;
                    toggle.setAttribute('aria-label', label);

                    if (animate) {
                        root.classList.add('theme-switching');
                        toggle.classList.add('spun');
                        clearTimeout(resetTimer);
                        resetTimer = setTimeout(function() {
                            root.classList.remove('theme-switching');
                            toggle.classList.remove('spun');
                        }, 400);
                    }
                }

                toggle.addEventListener('click', function() {
                    apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
                });

                apply(readStoredTheme(), false);
            }

            function initAll() {
                initLayoutChrome();
                initUserMenu();
                initThemeToggle();
            }

            // First load...
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAll);
            } else {
                initAll();
            }
            // ...and every Livewire SPA navigation (body content is swapped)
            document.addEventListener('livewire:navigated', initAll);
        })();
    </script>
    @livewireScripts
    <script>
        /* Re-apply the persisted theme after every Livewire SPA navigation.
           Navigate swaps <body> content; the <html data-theme> attribute set
           by the boot script survives, but re-asserting it keeps the theme
           synced if the user changed it in another tab meanwhile. */
        document.addEventListener('livewire:navigated', function () {
            var theme = 'light';
            try {
                var saved = localStorage.getItem('smartapp-theme');
                if (saved === 'dark' || saved === 'light') theme = saved;
            } catch (e) {}
            if (theme === 'light') {
                var m = document.cookie.match(/(?:^|;\s*)smartapp-theme=([^;]+)/);
                if (m && (m[1] === 'dark' || m[1] === 'light')) theme = m[1];
            }
            document.documentElement.setAttribute('data-theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#f1f5f9');
        });
    </script>

    @php
        /* Can an expired session be recovered without the user typing their
           password again? Yes when this browser carries Laravel's "remember me"
           cookie (set at login when the box is ticked) — the auth middleware
           signs the request back in from it. The session-expiry handler below
           reads this flag (via the data attribute on #dashboardFeedback) to
           choose between a silent reload and /login. */
        $smartappGuard = auth()->guard();
        $smartappCanRestoreSession = method_exists($smartappGuard, 'getRecallerName')
            && request()->cookies->has($smartappGuard->getRecallerName());
    @endphp

    <div
        id="dashboardFeedback"
        data-success="{{ session('success') }}"
        data-can-restore-session="{{ $smartappCanRestoreSession ? 'true' : 'false' }}"
        hidden
    ></div>

    {{-- SweetAlert2 (bundled locally, no CDN dependency) + a theme-aware
         helper used by Livewire components. --}}
    <script src="{{ public_asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script>

        window.smartAlert = window.smartAlert || (function () {
            function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }

            function themeOptions() {
                return {
                    background: isDark() ? '#1e293b' : '#ffffff',
                    color: isDark() ? '#e2e8f0' : '#1e293b',
                    customClass: {
                        popup: 'smart-alert-popup',
                        title: 'smart-alert-title',
                        htmlContainer: 'smart-alert-text',
                        confirmButton: 'smart-alert-confirm',
                        cancelButton: 'smart-alert-cancel'
                    }
                };
            }

            function success(title, text) {
                Swal.fire({
                    icon: 'success',
                    title: title,
                    text: text || '',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 4200,
                    timerProgressBar: true,
                    ...themeOptions()
                });
            }

            function confirmDelete(options) {
                return Swal.fire(Object.assign({
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                }, themeOptions(), options));
            }

            function confirm(options) {
                return Swal.fire(Object.assign({
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#3b82f6',
                    cancelButtonColor: '#64748b',
                }, themeOptions(), options));
            }

            /* Fired when the Laravel session has aged out and the page can no
               longer be used (see the session-expiry handler below). */
            function sessionExpired(options) {
                return Swal.fire(Object.assign({
                    icon: 'warning',
                    title: 'Session expired',
                    text: 'You were signed out after a long period of inactivity. Please sign in again.',
                    confirmButtonText: 'Sign in again',
                    confirmButtonColor: '#3b82f6',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }, themeOptions(), options));
            }

            return {
                success: success,
                confirmDelete: confirmDelete,
                confirm: confirm,
                sessionExpired: sessionExpired
            };
        })();

        (function () {
            var feedback = document.getElementById('dashboardFeedback');
            var message = feedback ? feedback.dataset.success : '';

            if (message && window.smartAlert) {
                window.smartAlert.success('Login successful', message);
            }
        })();

        (function () {
            var logoutForm = document.querySelector('#userDropdown form[action*="logout"]');
            if (!logoutForm) return;

            logoutForm.addEventListener('submit', function (event) {
                event.preventDefault();

                // Tell the 419 handler that any expired-session response now
                // incoming is the logout teardown, not an expiry to recover
                // from — otherwise a straggling poll pops the "page has
                // expired" dialog over the sign-out navigation.
                window.__smartappLoggingOut = true;

                if (!window.Swal) {
                    logoutForm.submit();
                    return;
                }

                smartAlert.confirm({
                    title: 'Log out?',
                    text: 'Are you sure you want to sign out?',
                    confirmButtonText: 'Yes, log out',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) {
                        logoutForm.submit();
                    } else {
                        // Cancelled: the session is still alive, so the expiry
                        // handler must stay armed for a real expiration.
                        window.__smartappLoggingOut = false;
                    }
                });
            });
        })();

        /* ---------- SESSION EXPIRY (Livewire HTTP 419) ----------
           Every dashboard page polls Livewire in the background (2s). When the
           session has aged out — the tab was suspended, the machine slept, the
           browser was closed with the page still open — those polls come back
           as 419 and Livewire's own handler pops a native
           "This page has expired. Would you like to refresh the page?" confirm,
           whose reload then lands on the login screen.

           Reload instead, without asking: the poll is failing either way, and
           the reload is the fix. Where it lands depends on the session:
             - remember-me browsers: the auth middleware signs the user back in
               from Laravel's remember cookie, so the page just works again;
             - everyone else: /login, where the notice below explains why.
           The flag in sessionStorage carries that explanation across the
           reload (and is cleared when the reload kept us signed in). */
        (function () {
            var EXPIRED_FLAG = 'smartappSessionExpired';

            function sessionIsKnownExpired() {
                try {
                    return window.sessionStorage.getItem(EXPIRED_FLAG) === '1';
                } catch (e) {
                    return false;
                }
            }

            function flagSessionExpired() {
                try {
                    window.sessionStorage.setItem(EXPIRED_FLAG, '1');
                } catch (e) {}
            }

            /* The Livewire script tag sits ABOVE this block and loads
               synchronously, so `livewire:init` has already fired by the time
               this code runs — registering through that event never armed the
               hook, and Livewire's native "This page has expired" confirm
               leaked through instead. `window.Livewire` exists right now, so
               hook directly: a hook registered late still fires for every
               future request. */
            /* Remember-me cookie present? Then the reload gets silently
               signed back in by the auth middleware. The flag is injected as
               a data attribute on #dashboardFeedback (Blade inside <script>
               trips JavaScript language checking in editors). */
            var canRestoreSession = (function () {
                var feedback = document.getElementById('dashboardFeedback');
                return !!(feedback && feedback.dataset.canRestoreSession === 'true');
            })();

            if (window.Livewire && typeof Livewire.hook === 'function') {
                Livewire.hook('request', function (request) {
                    request.fail(function (payload) {
                        if (payload.status !== 419) return;

                        // Always stop Livewire's native confirm + reload, for
                        // every expired response — several components poll at
                        // once, so a burst of 419s can arrive together (e.g.
                        // logout invalidating the session while in-flight
                        // polls are still resolving).
                        payload.preventDefault();

                        // React to the first 419 only, across reloads too:
                        // stragglers from the dying document must not retrigger.
                        if (sessionIsKnownExpired()) return;
                        flagSessionExpired();

                        // Signing out deliberately invalidates the session and
                        // navigates away — a 419 from a straggling poll is just
                        // the teardown, not an expiry to recover from.
                        if (window.__smartappLoggingOut) return;

                        if (canRestoreSession) {
                            // Remember-me cookie present: the reload gets
                            // silently signed back in by the auth middleware.
                            window.location.reload();
                        } else {
                            // No way back in: land on the login screen, which
                            // reads ?expired=1 and explains what happened.
                            window.location.href = '/login?expired=1';
                        }
                    });
                });
            }

            // Marks this document as the dashboard shell, so the SPA check
            // below can tell a swapped-in login page from a real page load.
            window.__smartappShell = true;

            // Made it back to a dashboard page? Then the session was fine (or
            // was restored), so there is nothing to explain on the login page.
            try {
                window.sessionStorage.removeItem(EXPIRED_FLAG);
            } catch (e) {}

            /* wire:navigate is SPA-style: with an expired session, clicking a
               sidebar link makes Livewire follow the auth redirect and swap the
               *login page* into this document. Do a real navigation instead, so
               the login screen — and its notice — load properly. */
            document.addEventListener('livewire:navigated', function () {
                if (!window.__smartappShell) return;

                if (document.querySelector('form[method="POST"][action*="/login"]')) {
                    flagSessionExpired();
                    window.location.href = '/login';
                }
            });
        })();
    </script>
</body>
</html>