<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

/** Admin mengatur approver payroll (users.approver_user_id) untuk user yang membuat payroll. */
final class ApproverController extends Controller
{
    public function index()
    {
        return view('payroll-baru.approver', [
            'user' => User::with(['karyawan', 'role'])->where('is_active', true)->whereHas('role', fn ($q) => $q->where('nama', Role::HR))->orderBy('username')->get(),
            'manager' => User::with('karyawan')->where('is_active', true)->whereHas('role', fn ($q) => $q->where('nama', Role::MANAGER))->orderBy('username')->get(),
        ]);
    }

    public function update(Request $request, int $id, AuditLogger $audit)
    {
        $user = User::findOrFail($id);
        $data = $request->validate(['approver_user_id' => ['nullable', 'integer', TenantRule::exists('users')]]);
        if (($data['approver_user_id'] ?? null) !== null) {
            $approver = User::with('role')->find($data['approver_user_id']);
            if ((int) $approver->id === (int) $user->id || ! $approver->is_active || $approver->role?->nama !== Role::MANAGER) {
                return back()->withErrors(['approver_user_id' => 'Approver harus user Manager aktif dan bukan user itu sendiri.']);
            }
        }

        $lama = $user->approver_user_id;
        $user->forceFill(['approver_user_id' => $data['approver_user_id'] ?? null])->save();
        $audit->log('updated', $user, old: ['approver_user_id' => $lama], new: ['approver_user_id' => $user->approver_user_id]);

        return back()->with('sukses', "Approver payroll untuk {$user->username} disimpan.");
    }
}
