<?php

namespace App\Http\Controllers;

use App\Domain\AdminService;
use App\Domain\CutiError;
use App\Domain\CutiStore;
use App\Domain\LeaveRules;
use App\Domain\LeaveService;
use App\Domain\ReplacementService;
use App\Domain\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class CutiController extends Controller
{
    public function __construct(private CutiStore $store, private LeaveService $leaves, private ReplacementService $replacements, private AdminService $admin, private ReportService $reports) {}

    public function health(): JsonResponse
    {
        DB::select('select 1');

        return response()->json(['ok' => true]);
    }

    public function configuration(): JsonResponse
    {
        return response()->json(['demo' => config('cuti.demo'), 'today' => LeaveRules::today(), 'mailMode' => config('cuti.mail_mode')]);
    }

    public function login(Request $request): JsonResponse
    {
        $body = Validator::make($request->all(), ['email' => 'required|email:rfc|max:200', 'password' => 'required|string|max:200'])->validate();

        return DB::transaction(function () use ($request, $body) {
            $row = DB::table('employees')->where('email', strtolower($body['email']))->lockForUpdate()->first();
            $actor = $row ? CutiStore::decode($row->data) : null;
            $fallback = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = password_verify($body['password'], $row->password_hash ?? $fallback);
            CutiError::check($actor && $actor['active'] && empty($actor['deletedAt']) && $valid, 'Email atau kata sandi tidak sesuai.', 401);
            $token = bin2hex(random_bytes(32));
            DB::table('cuti_sessions')->insert(['token' => CutiStore::digest($token), 'employee_id' => $actor['id'], 'expires_at' => now('UTC')->addHours(8)]);
            $secure = str_starts_with(config('app.url'), 'https://') || $request->isSecure();

            return response()->json($actor)->cookie(config('cuti.cookie'), $token, 480, '/', null, $secure, true, false, 'lax');
        });
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->attributes->get('actor'));
    }

    public function password(Request $request): JsonResponse
    {
        $body = Validator::make($request->all(), ['currentPassword' => 'required|string|max:200', 'newPassword' => ['required', 'string', 'max:72', 'regex:'.LeaveRules::PASSWORD_PATTERN], 'confirmPassword' => 'required|string|max:72'])->validate();
        CutiError::check($body['newPassword'] === $body['confirmPassword'], 'Konfirmasi kata sandi baru tidak sama.');
        CutiError::check($body['newPassword'] !== $body['currentPassword'], 'Gunakan kata sandi baru yang berbeda.');
        $actor = $request->attributes->get('actor');

        return DB::transaction(function () use ($actor, $body, $request) {
            DB::table('units')->where('id', $actor['unit'])->lockForUpdate()->first();
            $row = DB::table('employees')->where('id', $actor['id'])->lockForUpdate()->first();
            $current = CutiStore::decode($row->data);
            CutiError::check($current['active'] && empty($current['deletedAt']), 'Sesi berakhir. Silakan masuk kembali.', 401);
            CutiError::check(password_verify($body['currentPassword'], $row->password_hash), 'Kata sandi saat ini tidak sesuai.');
            DB::table('employees')->where('id', $actor['id'])->update(['password_hash' => password_hash($body['newPassword'], PASSWORD_BCRYPT, ['cost' => 12])]);
            DB::table('cuti_sessions')->where('employee_id', $actor['id'])->where('token', '!=', $request->attributes->get('session_hash'))->delete();
            $this->store->audit($actor, 'PASSWORD_CHANGE', $actor['id']);

            return response()->json(['ok' => true]);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('cuti_sessions')->where('token', $request->attributes->get('session_hash'))->delete();

        return response()->json(['ok' => true])->withoutCookie(config('cuti.cookie'));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $actor = $request->attributes->get('actor');
        $all = $this->store->requests($actor['unit']);
        $policy = $this->store->policy($actor['unit']);
        $sdm = in_array('SDM', $actor['roles'], true);
        $visible = array_values(array_filter($all, fn ($r) => $sdm ? $r['status'] !== 'DRAFT' : ($r['employeeId'] === $actor['id'] || ($r['replacement']['employeeId'] ?? '') === $actor['id'])));
        usort($visible, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        $quotas = [];
        foreach (LeaveRules::positions($policy) as [$code, $label]) {
            if ($sdm || in_array('ADMIN', $actor['roles'], true) || $code === $actor['position']) {
                $quotas[] = [...LeaveRules::quotaFor($code, $month, $all, LeaveRules::limitFor($code, $month, $policy, $all), $actor['id']), 'label' => $label];
            }
        }
        $calendar = [];
        foreach ($all as $leave) {
            if (in_array($leave['status'], LeaveRules::ACTIVE, true) && str_starts_with($leave['effectiveStart'], $month) && ($sdm || $leave['position'] === $actor['position'] || ($leave['replacement']['employeeId'] ?? '') === $actor['id'])) {
                $canRead = CutiStore::canRead($actor, $leave);
                $calendar[] = ['id' => $canRead ? $leave['id'] : '', 'name' => $canRead ? $leave['employeeName'] : 'Rekan satu posisi', 'position' => $leave['position'], 'status' => $leave['status'], 'days' => $leave['days']];
            }
        }
        $location = collect($this->store->outlets($actor['unit']))->firstWhere('id', CutiStore::outletId($actor));

        return response()->json(['requests' => $visible, 'quotas' => $quotas, 'calendar' => $calendar, 'positions' => LeaveRules::positions($policy), 'blocked' => LeaveRules::blockedDays($month, $policy['calendar']), 'calendarConfig' => $policy['calendar'], 'today' => LeaveRules::today(), 'outletName' => $location['name'] ?? 'Outlet belum diatur', 'unitName' => DB::table('units')->where('id', $actor['unit'])->value('name')]);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->leaves->preview($request->attributes->get('actor'), LeaveService::input($request->all())));
    }

    public function submit(Request $request): JsonResponse
    {
        return response()->json($this->leaves->submit($request->attributes->get('actor'), $request->all(), $request->header('Idempotency-Key', '')), 201);
    }

    public function detail(Request $request, string $id): JsonResponse
    {
        return response()->json($this->store->findLeave($request->attributes->get('actor'), $id));
    }

    public function replacements(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        CutiError::check(in_array('SDM', $actor['roles'], true), 'Akses SDM diperlukan.', 403);

        return response()->json($this->replacements->check($this->store->findLeave($actor, $id), $this->store->policy($actor['unit'])));
    }

    public function action(Request $request, string $id, string $action): JsonResponse
    {
        return response()->json($this->leaves->act($request->attributes->get('actor'), $id, $action, $request->all(), $request->header('Idempotency-Key', '')));
    }

    public function draft(Request $request): JsonResponse
    {
        return response()->json($this->leaves->draft($request->attributes->get('actor'), $request->all(), $request->header('Idempotency-Key', '')));
    }

    public function deleteDraft(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('actor');

        return response()->json($this->store->mutate($actor, $request->header('Idempotency-Key', ''), ['action' => 'deleteDraft', 'id' => $id], function () use ($actor, $id) {
            $leave = $this->store->findLeave($actor, $id);
            CutiError::check($leave['employeeId'] === $actor['id'] && $leave['status'] === 'DRAFT', 'Draft tidak ditemukan.', 404);
            DB::table('requests')->where('id', $id)->delete();

            return ['ok' => true];
        }));
    }

    public function admin(Request $request): JsonResponse
    {
        return response()->json($this->admin->data($request->attributes->get('actor')));
    }

    public function adminAction(Request $request, string $action, ?string $id = null): JsonResponse
    {
        $body = $request->all();
        if ($id !== null) {
            $body['id'] = $id;
        }

        return response()->json($this->admin->execute($request->attributes->get('actor'), $action, $body, $request->header('Idempotency-Key', '')));
    }

    public function report(Request $request, string $format): Response
    {
        $actor = $request->attributes->get('actor');
        CutiError::check(in_array('SDM', $actor['roles'], true), 'Akses SDM diperlukan.', 403);
        $month = $this->month($request, true);
        $rows = array_values(array_filter($this->store->requests($actor['unit']), fn ($r) => $r['status'] !== 'DRAFT' && ($month === 'all' || str_starts_with($r['effectiveStart'], $month))));
        $this->store->audit($actor, 'EXPORT', $month);

        return $this->reports->download($rows, $actor, $month, $format);
    }

    public function verify(Request $request): JsonResponse
    {
        $id = $request->query('id');
        $number = $request->query('num');
        CutiError::check((is_string($id) && strlen($id) <= 64) || (is_string($number) && strlen($number) <= 100), 'Nomor atau ID dokumen diperlukan.', 400);
        $found = collect($this->store->requests())->first(fn ($r) => ($id && $r['id'] === $id) || ($number && strcasecmp($r['number'], $number) === 0));
        if (! $found) {
            return response()->json(['valid' => false, 'message' => 'Surat keterangan cuti tidak ditemukan dalam sistem.'], 404);
        }

        return response()->json(['valid' => $found['status'] === 'APPROVED', 'id' => $found['id'], 'number' => $found['number'], 'employeeName' => $found['employeeName'], 'unitName' => DB::table('units')->where('id', $found['unit'])->value('name'), 'position' => $found['positionName'] ?? $found['position'], 'category' => $found['category'] === 'REGULAR' ? 'Cuti Reguler' : 'Cuti Darurat', 'subtype' => $found['subtype'], 'status' => $found['status'], 'effectiveStart' => $found['effectiveStart'], 'effectiveEnd' => $found['effectiveEnd'], 'duration' => $found['duration'], 'replacement' => isset($found['replacement']) ? array_intersect_key($found['replacement'], array_flip(['employeeName', 'position', 'outletName'])) : null, 'verifiedAt' => LeaveRules::timestamp()]);
    }

    private function month(Request $request, bool $allowAll = false): string
    {
        $value = $request->query('month');
        CutiError::check(is_string($value) && (($allowAll && $value === 'all') || preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $value)), 'Pilih bulan yang valid.');

        return $value;
    }
}
