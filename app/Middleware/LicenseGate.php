<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\License;

/** Sperrt Funktionen, die nicht (mehr) lizenziert sind; ohne gültige Lizenz bleibt die Installation lesbar. */
final class LicenseGate
{
    public static function handle(Request $request): ?Response
    {
        $req = License::requirement($request->method, $request->path);
        if ($req === null || License::has((string) $req['scope'])) {
            return null;
        }
        $status = License::status();
        $label = License::SCOPES[$req['scope']][0] ?? $req['scope'];
        $msg = $status['state'] === 'ok'
            ? 'Die Funktion «' . $label . '» ist in der Lizenz nicht enthalten.'
            : 'Die Installation ist im Nur-Lesen-Modus: ' . $status['message'];
        if ($request->wantsJson() || str_starts_with('/' . trim($request->path, '/'), '/api/')) {
            throw new HttpException(402, $msg, ['license' => $status['state'], 'scope' => $req['scope']]);
        }
        Session::flash('error', $msg);
        return Response::redirect(url('admin/license'));
    }
}
