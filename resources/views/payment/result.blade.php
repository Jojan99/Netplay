<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    @if ($autoReturn)
        <meta http-equiv="refresh" content="6;url={{ $primary['url'] }}">
    @endif
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f1f5f9;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #0f172a;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, .07);
            max-width: 420px;
            width: 100%;
            padding: 32px 26px 26px;
            text-align: center;
        }
        .icon {
            width: 62px; height: 62px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px;
            background: {{ $tone['soft'] }};
            color: {{ $tone['strong'] }};
        }
        h1 { font-size: 19px; margin: 0 0 8px; font-weight: 700; }
        .lead { font-size: 14px; line-height: 1.6; color: #475569; margin: 0 0 20px; }
        .amount { font-size: 30px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 4px; }
        dl {
            margin: 0 0 22px;
            border-top: 1px solid #f1f5f9;
            padding-top: 14px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 6px 14px;
            font-size: 13px;
            text-align: left;
        }
        dt { color: #94a3b8; }
        dd { margin: 0; color: #334155; text-align: right; word-break: break-all; }
        .btn {
            display: block;
            padding: 14px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            margin-bottom: 10px;
        }
        .btn-primary { background: {{ $primary['color'] }}; color: #fff; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .foot { margin-top: 14px; font-size: 12px; color: #94a3b8; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon" aria-hidden="true">{{ $tone['icon'] }}</div>

        <h1>{{ $title }}</h1>

        @if ($amount)
            <p class="amount">{{ $amount }}</p>
        @endif

        <p class="lead">{{ $message }}</p>

        @if ($details)
            <dl>
                @foreach ($details as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        @endif

        <a class="btn btn-primary" href="{{ $primary['url'] }}">{{ $primary['label'] }}</a>

        @if ($secondary)
            <a class="btn btn-secondary" href="{{ $secondary['url'] }}">{{ $secondary['label'] }}</a>
        @endif

        <p class="foot">
            @if ($autoReturn)
                Te llevamos de vuelta en unos segundos.
            @else
                Si tienes dudas, escríbenos y con gusto te ayudamos.
            @endif
        </p>
    </div>
</body>
</html>
