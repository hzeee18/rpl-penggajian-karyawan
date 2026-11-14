<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Payroll\Run\PenyetujuPayroll;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Menu Approval Payroll: boleh dibuka pemilik hak menu, atau penyetuju yang dipilih di Approver Payroll (AUDIT.md §77). */
final class AksesApprovalPayroll
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->bisa('pay-approval') || PenyetujuPayroll::boleh($user)), 403);

        return $next($request);
    }
}
