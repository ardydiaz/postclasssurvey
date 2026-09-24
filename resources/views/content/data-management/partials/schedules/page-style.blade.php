{{-- Page Style Partial --}}
@section('page-style')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --dm-pill-max: clamp(5.5rem, 16vw, 10rem);
            --dm-cell-max: clamp(8.5rem, 22vw, 13.5rem);
            --dm-stack-max: clamp(10.5rem, 28vw, 18rem);
        }

        .container-fluid {
            padding-left: 1.5rem;
            padding-right: 1.5rem;
            max-width: 100%;
        }

        .layout-page .content-wrapper > .container-xxl.container-p-y {
            padding-top: 1.5rem !important;
            padding-bottom: 1.5rem !important;
        }

        .layout-page .content-wrapper > .container-xxl.container-p-y > .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }

        #scheduleAlertContainer,
        #scheduleAlertContainer + .card {
            margin-top: 0 !important;
        }

        #scheduleAlertContainer:not(:empty) {
            margin-bottom: 1.5rem;
        }

        .schedule-card {
            background-color: var(--bs-card-bg);
            border: var(--bs-card-border-width) solid var(--bs-card-border-color);
            border-radius: var(--bs-card-border-radius);
            box-shadow: var(--bs-card-box-shadow, 0 2px 6px rgba(67, 89, 113, 0.12));
        }

        .schedule-action-card {
            background-color: #5c297c;
            color: #ffffff;
        }

        .schedule-action-card .card-title,
        .schedule-action-card .card-title i {
            color: #ffb736;
        }

        .schedule-action-card p,
        .schedule-action-card .text-muted {
            color: #ffffff !important;
        }

        .schedule-action-card .btn-schedule-action {
            background-color: #ffb736;
            color: #5c297c;
            border: none;
            font-weight: 600;
            transition: none;
            box-shadow: none;
        }

        .schedule-action-card .btn-schedule-action i {
            color: #5c297c;
        }

        .schedule-action-card .btn-schedule-action:hover,
        .schedule-action-card .btn-schedule-action:focus {
            background-color: #e6a431;
            color: #5c297c;
            transform: none;
            box-shadow: none;
        }

        .time-range-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .time-range-separator {
            font-weight: 600;
            color: #6c757d;
            letter-spacing: 0.04em;
        }

        .schedule-open-hour-btn {
            border: 1px solid rgba(92, 41, 124, 0.18);
            border-radius: 999px;
            background: rgba(92, 41, 124, 0.08);
            color: #5c297c;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.4rem 0.75rem;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .schedule-open-hour-btn:hover,
        .schedule-open-hour-btn:focus {
            background: rgba(255, 183, 54, 0.22);
            border-color: rgba(255, 183, 54, 0.55);
            color: #3a0050;
            outline: none;
        }

        .btn-deleted-schedule {
            min-height: calc(2.25rem + 2px);
            padding: 0 1rem;
            border: 1px solid rgba(92, 41, 124, 0.18);
            border-radius: 0.55rem;
            background: #5c297c;
            color: #ffffff;
            font-size: 0.85rem;
            font-weight: 700;
            box-shadow: 0 0.45rem 1rem rgba(92, 41, 124, 0.14);
        }

        .btn-deleted-schedule:hover,
        .btn-deleted-schedule:focus {
            border-color: #e6a431;
            background: #ffb736;
            color: #3a0050;
            box-shadow: 0 0.55rem 1.1rem rgba(230, 164, 49, 0.2);
        }

        .btn-restore-schedule {
            background: linear-gradient(135deg, #5c297c, #6f2a8f);
            border: 1px solid #5c297c;
            color: #ffffff;
            border-radius: 999px;
            font-weight: 700;
            padding: 0.45rem 0.9rem;
            box-shadow: 0 0.55rem 1.2rem rgba(92, 41, 124, 0.18);
        }

        .btn-restore-schedule:hover,
        .btn-restore-schedule:focus {
            background: linear-gradient(135deg, #ffb736, #e6a431);
            border-color: #e6a431;
            color: #3a0050;
            box-shadow: 0 0.65rem 1.35rem rgba(230, 164, 49, 0.24);
        }

        .btn-force-delete-schedule {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            border: 1px solid #b91c1c;
            color: #ffffff;
            border-radius: 999px;
            font-weight: 700;
            padding: 0.45rem 0.9rem;
            box-shadow: 0 0.55rem 1.2rem rgba(220, 38, 38, 0.18);
        }

        .btn-force-delete-schedule:hover,
        .btn-force-delete-schedule:focus {
            background: linear-gradient(135deg, #ffb736, #ef4444);
            border-color: #ef4444;
            color: #3a0050;
            box-shadow: 0 0.65rem 1.35rem rgba(239, 68, 68, 0.24);
        }

        .time-dropdown {
            position: relative;
        }

        .time-dropdown-toggle {
            border: 1px solid #d9dee3;
            border-radius: 0.6rem;
            padding: 0.6rem 0.85rem;
            background-color: #ffffff;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            color: #0f172a;
            font-weight: 500;
            box-shadow: none;
            transition: border-color 0.2s ease;
        }

        .time-dropdown-toggle:hover,
        .time-dropdown-toggle:focus,
        .time-dropdown.show .time-dropdown-toggle {
            border-color: #94a3b8;
            background-color: #ffffff;
            box-shadow: none;
        }

        .time-dropdown-toggle:focus {
            outline: none;
        }

        .time-dropdown-label {
            font-weight: 500;
            color: var(--bs-body-color, #4b5563);
            font-size: 0.95rem;
        }

        .time-dropdown-label.is-placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .time-dropdown .dropdown-menu {
            width: 100%;
            border-radius: 0.75rem;
            box-shadow: 0 15px 30px rgba(15, 23, 42, 0.15);
            padding: 0.75rem 0.85rem;
            top: calc(100% + 0.35rem) !important;
            left: 0 !important;
            right: 0 !important;
            transform: none !important;
            margin-top: 0 !important;
            z-index: 1085;
        }

        .time-dropdown .dropdown-menu::before {
            display: none;
        }

        .time-dropdown-search {
            margin-bottom: 0.5rem;
        }

        .time-dropdown-list {
            max-height: 13rem;
            overflow-y: auto;
        }

        .time-dropdown-list.is-unlimited {
            max-height: none;
        }

        .time-dropdown .dropdown-item {
            border-radius: 0.45rem;
            font-weight: 500;
            color: #111827;
            padding: 0.5rem 0.75rem;
            min-height: 3.25rem;
            display: flex;
            align-items: center;
            background-color: #ffffff;
            border: 1px solid transparent;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }

        .time-dropdown .dropdown-item:hover,
        .time-dropdown .dropdown-item:focus {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }

        .time-range-group .time-dropdown {
            flex: 1;
        }

        .course-dropdown {
            position: relative;
        }

        .course-dropdown-toggle {
            border: 1px solid #d9dee3;
            border-radius: 0.6rem;
            padding: 0.6rem 0.85rem;
            background-color: #ffffff;
            width: 100%;
            cursor: pointer;
            color: #0f172a;
            font-weight: 500;
            box-shadow: none;
            transition: border-color 0.2s ease;
        }

        .course-dropdown-toggle:hover,
        .course-dropdown-toggle:focus,
        .course-dropdown-toggle:active,
        .course-dropdown.show .course-dropdown-toggle {
            border-color: #94a3b8;
            background-color: #ffffff;
            box-shadow: none;
        }

        .course-dropdown-toggle:focus {
            outline: none;
        }

        .course-dropdown-toggle i {
            color: #94a3b8;
        }

        .course-dropdown-label {
            font-weight: 500;
            color: var(--bs-body-color, #4b5563);
            font-size: 0.95rem;
        }

        .course-dropdown-label.is-placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .course-dropdown .dropdown-menu {
            width: 100%;
            border-radius: 0.75rem;
            box-shadow: 0 15px 30px rgba(15, 23, 42, 0.15);
            padding: 0.75rem 0.85rem;
            top: calc(100% + 0.4rem) !important;
            left: 0 !important;
            right: 0 !important;
            transform: none !important;
            margin-top: 0 !important;
            z-index: 1085;
        }

        .course-dropdown .dropdown-menu::before {
            display: none;
        }

        .course-dropdown-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.45rem 0.65rem;
            max-height: calc((2 * 3.25rem) + 0.75rem);
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .course-dropdown-list.is-unlimited {
            max-height: calc((2 * 3.25rem) + 0.75rem);
        }

        .course-dropdown .dropdown-item {
            border-radius: 0.45rem;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
            font-weight: 500;
            color: #111827;
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: #ffffff;
            border: 1px solid transparent;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            min-height: 3.25rem;
        }

        .course-dropdown .dropdown-item:hover,
        .course-dropdown .dropdown-item:focus {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }

        .course-dropdown .dropdown-item .course-dropdown-title {
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 600;
            color: #0f172a;
        }

        .course-dropdown .dropdown-item small {
            font-size: 0.75rem;
            color: #94a3b8;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            line-height: 1.2;
            font-weight: 400;
        }

        .course-dropdown-title {
            font-size: 0.95rem;
        }

        .course-dropdown .dropdown-item small + small {
            margin-top: 0.15rem;
        }

        .course-dropdown-info {
            display: flex;
            flex-wrap: wrap;
            gap: 0.15rem 0.35rem;
            font-weight: 500;
            color: #94a3b8;
        }

        .course-dropdown-info span {
            display: inline-flex;
            align-items: center;
        }

        .course-dropdown-info span + span::before {
            content: '|';
            margin: 0 0.25rem 0 0;
            color: #94a3b8;
            font-weight: 600;
        }

        .course-dropdown-search {
            border-radius: 0.5rem;
            margin-bottom: 0.6rem;
        }

        .day-multiselect {
            position: relative;
        }

        .day-multiselect-toggle {
            border: 1px solid #d9dee3;
            border-radius: 0.6rem;
            padding: 0.6rem 0.85rem;
            background-color: #ffffff;
            min-height: 3.1rem;
            cursor: pointer;
            color: #0f172a;
            font-weight: 500;
            box-shadow: none;
            gap: 0.4rem;
        }

        .day-multiselect-toggle:hover,
        .day-multiselect-toggle:focus,
        .day-multiselect.show .day-multiselect-toggle {
            border-color: #94a3b8;
            background-color: #ffffff;
            box-shadow: none;
        }

        .day-multiselect-toggle:focus {
            outline: none;
        }

        .day-multiselect-content {
            min-height: 1.5rem;
        }

        .day-multiselect-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .day-multiselect-placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .day-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            background-color: #e3e8f1;
            color: #475569;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .day-chip button {
            border: none;
            background: transparent;
            color: inherit;
            font-size: 0.85rem;
            padding: 0;
            line-height: 1;
        }

        .day-multiselect-search {
            position: relative;
            margin-bottom: 0.75rem;
        }

        .day-multiselect-search input {
            padding-left: 0.85rem;
            border-radius: 0.6rem;
        }

        .day-multiselect .dropdown-menu {
            width: 100%;
            border-radius: 0.85rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.2);
            padding: 1rem;
            top: calc(100% + 0.5rem) !important;
            bottom: auto !important;
            transform: none !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1105;
        }

        .day-multiselect-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.45rem 0.5rem;
            max-height: calc((2 * 3rem) + 0.5rem);
            overflow-y: auto;
        }

        .day-multiselect-option {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.65rem;
            padding: 0.5rem 0.85rem;
            cursor: pointer;
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }

        .day-multiselect-option:hover,
        .day-multiselect-option:focus-within {
            border-color: #c7d2fe;
            background-color: #eef2ff;
        }

        .day-multiselect-option span {
            font-weight: 600;
            color: #0f172a;
        }

        .schedule-card--table {
            border-radius: var(--bs-card-border-radius);
            padding: 0;
        }

        .modal-content.schedule-card {
            background-color: #ffffff;
            border-radius: 0.75rem;
            border: var(--bs-card-border-width) solid var(--bs-card-border-color);
            box-shadow: var(--bs-card-box-shadow, 0 2px 6px rgba(67, 89, 113, 0.12));
            overflow: visible;
        }

        .schedule-card--table .card-body {
            padding: var(--bs-card-spacer-y, 1.5rem) var(--bs-card-spacer-x, 1.5rem);
        }

        .schedule-card--table .card-body.pt-0 {
            padding-top: 0 !important;
        }

        .schedule-card--table .card-body.border-0,
        .schedule-card--table .card-body.pt-0,
        .schedule-card--table .card-body.pb-0 {
            padding-left: var(--bs-card-spacer-x, 1.5rem) !important;
            padding-right: var(--bs-card-spacer-x, 1.5rem) !important;
        }

        .schedule-controls label {
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            color: #64748b;
        }

        .schedule-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: auto;
        }

        .schedule-table thead th {
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: #94a3b8;
            border-bottom: 1px solid #eef2f6;
            padding: 0.85rem 0.75rem 0.7rem;
            background-color: #ffffff;
            vertical-align: middle;
            text-align: left;
            line-height: 1.2;
        }

        .schedule-table thead th,
        .schedule-table tbody td {
            white-space: nowrap;
        }

        .table-text-truncate {
            display: inline-block;
            vertical-align: middle;
            min-width: 0;
            max-width: var(--dm-cell-max);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .table-text-truncate.is-wide {
            max-width: var(--dm-stack-max);
        }

        .table-cell-stack {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            min-width: 0;
            max-width: var(--dm-stack-max);
            align-items: flex-start;
        }

        .table-cell-stack > * {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
        }

        .table-cell-stack.is-wide {
            max-width: var(--dm-stack-max);
        }

        .schedule-table thead th.sortable {
            cursor: pointer;
            user-select: none;
        }

        .schedule-table thead th.sortable .sort-indicator {
            display: inline-flex;
            flex-direction: column;
            margin-left: 0.3rem;
            color: #cbd5f5;
            font-size: 0.65rem;
        }

        .schedule-table thead th.sortable .sort-indicator .icon-up,
        .schedule-table thead th.sortable .sort-indicator .icon-down {
            display: none;
        }

        .schedule-table thead th.sortable[data-sort-state="asc"] .sort-indicator .icon-up,
        .schedule-table thead th.sortable[data-sort-state="desc"] .sort-indicator .icon-down {
            display: inline-flex;
            color: #4f46e5;
        }

        .schedule-table thead th:first-child,
        .schedule-table tbody td:first-child {
            width: 48px;
            white-space: normal;
        }

        .schedule-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background-color 0.2s ease, box-shadow 0.2s ease;
        }

        .schedule-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .schedule-table tbody tr.is-selected {
            background-color: #eef2ff;
            box-shadow: inset 0 0 0 1px #c7d2fe;
        }

        .schedule-table tbody td {
            padding: 1rem 0.75rem;
            color: #1f2937;
            font-size: 0.85rem;
            vertical-align: middle;
            text-align: left;
        }

        .schedule-table tbody td.actions-cell {
            text-align: left;
        }

        .schedule-pill {
            display: inline-block;
            padding: 0.25rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            background-color: var(--pill-bg, #e2e8f0);
            color: var(--pill-color, #475569);
            max-width: var(--dm-pill-max);
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: center;
        }

        .schedule-pill--course {
        }

        .schedule-term-stack {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }

        .schedule-term-stack small {
            font-size: 0.76rem;
            font-weight: 700;
            line-height: 1.1;
            padding-left: 0.15rem;
        }

        .schedule-pill-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            max-width: var(--dm-stack-max);
        }

        .schedule-icon-btn {
            border: 1px solid rgba(92, 41, 124, 0.14);
            background: linear-gradient(135deg, rgba(92, 41, 124, 0.1), rgba(255, 183, 54, 0.14));
            color: #5c297c;
            border-radius: 0.75rem;
            width: 2.25rem;
            height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            box-shadow: 0 0.45rem 1rem rgba(44, 0, 63, 0.08);
        }

        .schedule-icon-btn:hover,
        .schedule-icon-btn:focus,
        .schedule-icon-btn.show {
            border-color: rgba(255, 183, 54, 0.65);
            background: linear-gradient(135deg, #5c297c, #7a2f8f);
            color: #ffb736;
            box-shadow: 0 0.75rem 1.35rem rgba(92, 41, 124, 0.22);
            transform: translateY(-1px);
        }

        .schedule-detail-grid {
            display: grid;
            gap: 0.85rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .schedule-detail-item {
            background: #ffffff;
            border: 1px solid #e7edf5;
            border-radius: 10px;
            padding: 0.9rem 1rem;
        }

        .schedule-detail-item small {
            color: #8a9bb3;
            display: block;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            margin-bottom: 0.35rem;
            text-transform: uppercase;
        }

        .schedule-detail-item strong {
            color: #2f4056;
            white-space: normal;
        }

        .schedule-detail-item.is-wide {
            grid-column: 1 / -1;
        }

        @media (max-width: 575.98px) {
            .schedule-detail-grid {
                grid-template-columns: 1fr;
            }
        }

        .schedule-search-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            min-height: calc(2.25rem + 2px);
            padding: 0 0.75rem;
            max-width: 340px;
            width: 100%;
            transition: border-color 0.2s ease;
        }

        .schedule-search-wrapper:focus-within {
            border-color: #4f46e5;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1);
        }

        .schedule-search-icon {
            color: #94a3b8;
            font-size: 1.1rem;
        }

        .schedule-search-input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 0.95rem;
            background: transparent;
            height: 100%;
            padding-top: 0;
            padding-bottom: 0;
        }

        .schedule-search-input::placeholder {
            color: #9ca3af;
        }

        .schedule-search-clear {
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 1.2rem;
            line-height: 1;
            padding: 0;
            cursor: pointer;
            display: none;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 999px;
            align-items: center;
            justify-content: center;
        }

        .schedule-search-clear.is-visible {
            display: inline-flex;
        }

        .schedule-search-clear:hover {
            color: #1f2937;
            background-color: rgba(148, 163, 184, 0.15);
        }

        .schedule-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: nowrap;
            margin-bottom: 2.5rem;
        }

        .schedule-toolbar-title {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            font-weight: 600;
            color: #111827;
        }

        .schedule-toolbar-title i {
            font-size: 1.8rem;
            color: #111827;
        }

        .schedule-modal-dialog {
            max-width: 42%;
        }

        .schedule-modal-dialog--narrow {
            max-width: 42%;
        }

        .schedule-modal-header {
            background-color: #f5f7fb;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
        }

        .schedule-modal-body {
            padding: 1.5rem;
        }

        .schedule-modal-footer {
            border-top: none;
            justify-content: flex-end;
            gap: 1rem;
            padding: 1rem 1.5rem;
        }

        .schedule-modal-footer .btn {
            min-width: 120px;
        }

        .schedule-modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #475569;
            font-size: 1.35rem;
            line-height: 1;
            padding: 0;
        }

        .schedule-modal-close:focus {
            outline: none;
            box-shadow: none;
        }

        .schedule-modal-body .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            letter-spacing: normal;
        }

        .schedule-modal-body .form-select,
        .schedule-modal-body .form-control {
            border-radius: 0.6rem;
            padding: 0.6rem 0.85rem;
        }

        .schedule-modal-btn {
            transition: none;
        }

        .schedule-modal-btn:hover,
        .schedule-modal-btn:focus,
        .schedule-modal-btn:active {
            transform: none;
            box-shadow: none;
        }

        @media (max-width: 992px) {
            .schedule-modal-dialog--narrow {
                max-width: 70%;
            }
        }

        @media (max-width: 768px) {
            .schedule-modal-dialog--narrow {
                max-width: 90%;
            }
        }

        .schedule-bulk-bar {
            position: fixed;
            left: 50%;
            transform: translateX(-50%);
            bottom: 24px;
            display: inline-flex;
            align-items: center;
            gap: 1rem;
            background-color: #ffffff;
            color: #1f2937;
            padding: 0.85rem 1.5rem;
            border-radius: 999px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
            border: 1px solid #e2e8f0;
            z-index: 1040;
        }

        .schedule-bulk-bar.d-none {
            display: none !important;
        }

        .schedule-bulk-btn {
            border: none;
            background: transparent;
            color: inherit;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            cursor: pointer;
        }

        .schedule-bulk-btn--danger {
            background-color: rgba(220, 38, 38, 0.08);
            color: #dc2626 !important;
            border: 1px solid rgba(220, 38, 38, 0.25);
        }

        .schedule-bulk-btn--danger i {
            color: #dc2626 !important;
        }

        .schedule-bulk-close {
            border: none;
            background: transparent;
            color: #475569;
            font-size: 1.2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            cursor: pointer;
        }

        .schedule-checkbox {
            width: 1.05rem;
            height: 1.05rem;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            cursor: pointer;
        }

        .schedule-checkbox:checked {
            background-color: #4f46e5;
            border-color: #4f46e5;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            padding: 0.55rem 1.4rem;
            font-weight: 600;
            font-size: 0.9rem;
            transition: none;
            box-shadow: none;
        }

        .btn.btn-primary {
            background-color: #2563eb;
            border: none;
            color: #ffffff;
        }

        .btn.btn-primary:hover,
        .btn.btn-primary:focus,
        .btn.btn-primary:active {
            background-color: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }

        .btn.btn-secondary {
            background-color: #dc2626;
            border: none;
            color: #ffffff;
        }

        .btn.btn-secondary:hover,
        .btn.btn-secondary:focus,
        .btn.btn-secondary:active {
            background-color: #dc2626;
            border-color: #dc2626;
            color: #ffffff;
        }

        .btn.btn-tertiary {
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0.55rem 1.2rem;
        }

        .btn.btn-tertiary:hover,
        .btn.btn-tertiary:focus,
        .btn.btn-tertiary:active {
            background: transparent;
            color: #64748b;
            box-shadow: none;
        }

        .btn-schedule-primary {
            background-color: #5c297c;
            border: 1px solid #5c297c;
            color: #ffffff;
            font-weight: 600;
            transition: none;
        }

        .btn-schedule-primary:hover,
        .btn-schedule-primary:focus,
        .btn-schedule-primary:active {
            background-color: #4b2266;
            border-color: #4b2266;
            color: #ffffff;
            box-shadow: none;
        }

        .btn-schedule-primary:active {
            background-color: #ffffff;
            color: #4b2266;
            border-color: #4b2266;
        }

        .btn-schedule-danger {
            background-color: #dc3545;
            border: 1px solid #dc3545;
            color: #ffffff;
            font-weight: 600;
            transition: none;
        }

        .btn-schedule-danger:hover,
        .btn-schedule-danger:focus,
        .btn-schedule-danger:active {
            background-color: #b92c39;
            border-color: #b92c39;
            color: #ffffff;
            box-shadow: none;
        }

        .btn-schedule-danger:active {
            background-color: #ffffff;
            color: #b92c39;
            border-color: #b92c39;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1.5rem 0;
            color: #64748b;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #111827;
        }

        .empty-state p {
            color: #6b7280;
        }

        div[data-table-id="schedulesTable"] [data-table-info] {
            color: #6b7280;
            font-size: 0.875rem;
        }

        div[data-table-id="schedulesTable"] .pagination .page-link {
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 0.9rem;
            margin: 0 0.1rem;
            color: #64748b;
            background-color: #e2e8f0;
            font-weight: 600;
            transition: background-color 0.15s ease;
            box-shadow: none;
        }

        div[data-table-id="schedulesTable"] .pagination .page-link:hover:not(.disabled) {
            background-color: #cbd5e1;
            color: #475569;
        }

        div[data-table-id="schedulesTable"] .pagination .page-item.active .page-link {
            background-color: #5c297c;
            color: #ffffff;
        }

        div[data-table-id="schedulesTable"] .pagination .page-item.active .page-link:hover {
            background-color: #4b2266;
            color: #ffffff;
        }

        div[data-table-id="schedulesTable"] .pagination .page-link span {
            color: inherit;
        }

        div[data-table-id="schedulesTable"] .pagination .page-item.disabled .page-link {
            background-color: #e2e8f0;
            color: #94a3b8;
        }

        @media (max-width: 992px) {
            .schedule-card--table {
                padding: 1.25rem;
            }

            .schedule-table tbody td {
                padding: 0.85rem 0.6rem;
            }
        }

        @media (max-width: 768px) {
            .schedule-table tbody td {
                padding: 0.75rem;
            }

            .schedule-bulk-bar {
                bottom: 90px;
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        .table-filter-dropdown .filter-toggle {
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0 0.75rem;
            background-color: #ffffff;
            font-weight: 600;
            font-size: 0.95rem;
            color: #0f172a;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            line-height: 1.2;
            min-height: calc(2.25rem + 2px);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .table-filter-dropdown .filter-toggle i {
            color: #94a3b8;
            font-size: 1rem;
        }

        .table-filter-dropdown .filter-toggle:focus,
        .table-filter-dropdown .filter-toggle:focus-visible {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1);
        }

        .table-filter-dropdown .filter-toggle.is-active {
            border-color: #5c297c;
            color: #5c297c;
        }

        .table-filter-dropdown .dropdown-menu {
            min-width: 260px;
            border-radius: 0.75rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 35px rgba(15, 23, 42, 0.1);
        }

        .table-filter-dropdown label {
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: #94a3b8;
        }

        .table-filter-reset {
            font-size: 0.8rem;
            font-weight: 600;
            color: #5c297c;
        }
    </style>
@endsection
