{{--
    The shell every error page is drawn in.

    THREE RULES, AND THEY ARE ALL THE SAME RULE. An error page is the one page in the system that
    is rendered when something has already gone wrong, so it must not be able to go wrong itself.

    1. NO `@vite`. The asset helper throws when `public/build/manifest.json` is missing, which on a
       cPanel deployment is the single most likely thing to be missing — assets are built on a
       machine that is not the server and uploaded separately (docs/12). A 500 page that needs the
       build to render turns a broken deploy into a blank screen with no explanation.
    2. NO DATABASE. Not the organization's name, not its logo, not a support address from settings.
       A 500 is very often the database, and a page that queries it to tell you the database is
       down recurses into a raw framework stack trace.
    3. NO AUTH AND NO LAYOUT. `components.layouts.app` builds a sidebar from the actor's permission
       set (§96), which needs a resolved tenant, a session and a user. A 419 means the session is
       exactly what has gone.

    So: one file, inline styles, static text. It is plainer than the rest of the application on
    purpose, and it is the only page in it that always renders.

    WHAT IT MAY SAY. The status, what it means in ordinary words, and what to do next. Never a
    record name, an id, a table, a query, a file path or a framework version — §84 and §75 are the
    reason. A cross-tenant id and an id that was never issued must produce the same page down to
    the byte, because any difference between them is a confirmation that the record exists.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --strong: #0f172a;
            --muted: #64748b;
            --brand: #0f4c81;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #020617;
                --card: #0f172a;
                --border: #1e293b;
                --strong: #f1f5f9;
                --muted: #94a3b8;
                --brand: #60a5fa;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--strong);
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .panel {
            width: 100%;
            max-width: 32rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 32px;
        }

        .code {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0 0 10px;
        }

        h1 {
            font-size: 20px;
            line-height: 1.35;
            margin: 0 0 12px;
        }

        p {
            margin: 0 0 12px;
            color: var(--muted);
        }

        p:last-child { margin-bottom: 0; }

        ul {
            margin: 0 0 12px;
            padding-left: 20px;
            color: var(--muted);
        }

        li { margin-bottom: 4px; }

        .actions {
            margin-top: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        a.button, button.button {
            display: inline-block;
            padding: 9px 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--strong);
            font: inherit;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
        }

        a.button.primary {
            background: var(--brand);
            border-color: var(--brand);
            color: #ffffff;
        }

        .ref {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            font-size: 13px;
            color: var(--muted);
        }

        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            color: var(--strong);
        }
    </style>
</head>
<body>
    <main class="panel">
        <p class="code">@yield('code')</p>

        <h1>@yield('title')</h1>

        @yield('body')

        <div class="actions">
            @hasSection('actions')
                @yield('actions')
            @else
                <a class="button primary" href="{{ url('/') }}">Back to the dashboard</a>
            @endif
        </div>

        @hasSection('reference')
            <div class="ref">@yield('reference')</div>
        @endif
    </main>
</body>
</html>
