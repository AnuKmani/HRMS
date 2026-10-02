{{--
    GET /reset-password — the only server-rendered page in the application,
    and it exists for one reason: the email's action button has to land
    somewhere that works.

    The product is a Flutter app with no web UI, so this page is not a
    second interface to keep in step with anything — it takes the two values
    the API already validates (`token`, `email`), lets the person choose a
    password, and posts them to POST /api/v1/auth/reset-password exactly as
    the app would. There is no session, no second store, and nothing here
    can be used to reset anything the link did not already authorize.

    Everything rendered is escaped, and the JSON payload is built with
    JSON_HEX_* so a token containing `<` cannot break out of the script.
    Nothing is cached: a password page that a shared machine serves from
    its own cache afterwards is a password page with a longer memory than
    it needs.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Reset your password</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f5f7;
            color: #1c1e21;
            padding: 24px;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e4e8;
            border-radius: 10px;
            padding: 32px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
        }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p.lede { color: #5b6067; margin: 0 0 22px; font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            font-size: 15px;
            border: 1px solid #ccd0d6;
            border-radius: 6px;
            background: #fff;
            color: inherit;
        }
        input:focus { outline: 2px solid #2f6fed; outline-offset: -1px; border-color: #2f6fed; }
        button {
            margin-top: 22px;
            width: 100%;
            padding: 11px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: #2f6fed;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }
        button:disabled { opacity: .6; cursor: default; }
        #result { margin-top: 16px; font-size: 14px; min-height: 1.5em; }
        #result.ok { color: #157a3f; }
        #result.bad { color: #b3261e; }
        .foot { margin-top: 20px; font-size: 12px; color: #777c85; }
        @media (prefers-color-scheme: dark) {
            body { background: #17191c; color: #e7e9ec; }
            .card { background: #202327; border-color: #34383e; }
            p.lede, .foot { color: #a7adb6; }
            input { background: #17191c; border-color: #454a52; }
        }
    </style>
</head>
<body>
<div class="card">
    @if ($token === '' || $email === '')
        <h1>That link is incomplete</h1>
        <p class="lede">
            This page needs the code and address that came with your reset
            email. Open the link from the email again, or ask for a new one
            from the sign-in screen.
        </p>
    @else
        <h1>Choose a new password</h1>
        <p class="lede">For the account <strong>{{ $email }}</strong>.</p>

        <form id="reset" novalidate>
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>

            <label for="confirmation">Confirm new password</label>
            <input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>

            <button type="submit" id="submit">Set new password</button>
        </form>

        <div id="result" role="status" aria-live="polite"></div>
    @endif

    <p class="foot">
        The link expires 60 minutes after it was sent and works once. After
        this, sign in to the app with your new password.
    </p>
</div>

@unless ($token === '' || $email === '')
<script>
    (function () {
        // Built from JSON_HEX_* so a value containing `<` or `'` cannot
        // terminate the string it is embedded in — a reset link is
        // attacker-influenced by nature (anybody can request one for any
        // address), and this page is the one place it lands in HTML.
        var payload = {!! json_encode([
            'token' => $token,
            'email' => $email,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

        var form = document.getElementById('reset');
        var result = document.getElementById('result');
        var submit = document.getElementById('submit');

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var body = {
                token: payload.token,
                email: payload.email,
                password: document.getElementById('password').value,
                password_confirmation: document.getElementById('confirmation').value
            };

            submit.disabled = true;
            result.className = '';
            result.textContent = 'Working…';

            fetch('/api/v1/auth/reset-password', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(body)
            }).then(function (response) {
                return response.json().then(function (json) {
                    return { ok: response.ok, json: json };
                }).catch(function () {
                    return { ok: false, json: { message: 'The server sent an unexpected reply. Try again.' } };
                });
            }).then(function (outcome) {
                if (outcome.ok) {
                    result.className = 'ok';
                    result.textContent = outcome.json.message || 'Password updated. You can now sign in to the app.';
                    form.remove();
                    return;
                }

                result.className = 'bad';
                // 422 carries the specific field, 429 says the throttle
                // tripped, everything else is one honest sentence.
                if (outcome.json.errors) {
                    var first = Object.values(outcome.json.errors)[0];
                    result.textContent = Array.isArray(first) ? first[0] : first;
                } else {
                    result.textContent = outcome.json.message || 'That did not work. Request a new link and try again.';
                }
            }).catch(function () {
                result.className = 'bad';
                result.textContent = 'Could not reach the server. Check your connection and try again.';
            }).finally(function () {
                submit.disabled = false;
            });
        });
    })();
</script>
@endunless
</body>
</html>
