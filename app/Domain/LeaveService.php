<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class LeaveService
{
    public function __construct(private CutiStore $store, private ReplacementService $replacements) {}

    public static function input(array $body): array
    {
        $input = Validator::make($body, [
            'category' => 'required|in:REGULAR,EMERGENCY',
            'subtype' => 'required|string|min:2|max:120',
            'reason' => 'required|string|min:10|max:2000',
            'start' => ['required', fn ($attribute, $value, $fail) => LeaveRules::validDate($value) ?: $fail('Tanggal mulai tidak valid.')],
            'end' => ['required', fn ($attribute, $value, $fail) => LeaveRules::validDate($value) ?: $fail('Tanggal akhir tidak valid.')],
            'email' => 'required|email:rfc|max:200',
            'phone' => ['required', 'string', 'regex:/^\+?[\d ()-]{8,20}$/'],
        ])->validate();
        $domains = config('cuti.allowed_email_domains');
        CutiError::check(! $domains || in_array(strtolower(explode('@', $input['email'])[1]), $domains, true), 'Domain email tidak diizinkan organisasi.');

        return $input;
    }

    public function preview(array $actor, array $input): array
    {
        $policy = $this->store->policy($actor['unit']);
        $all = $this->store->requests();
        $local = array_filter($all, fn ($r) => $r['unit'] === $actor['unit']);
        $result = LeaveRules::evaluate($input, $actor, $all, $policy['calendar'], LeaveRules::limitFor($actor['position'], substr($input['start'], 0, 7), $policy, $local), LeaveRules::today());
        if (collect($local)->contains(fn ($r) => ReplacementService::assignedDuring($r, $actor['id']) && ReplacementService::overlaps($r['days'], $result['days']))) {
            $result['errors'][] = ['code' => 'PGS_DUTY', 'message' => 'Anda memiliki tugas PGS pada tanggal tersebut. Hubungi SDM sebelum mengajukan cuti.'];
        }
        $result['fingerprint'] = CutiStore::digest(['input' => $input, 'days' => $result['days'], 'calendar' => $policy['calendar'], 'position' => $actor['position'], 'unit' => $actor['unit'], 'today' => LeaveRules::today()]);
        if ($result['effectiveStart']) {
            $result['scheduledAt'] = LeaveRules::reminderAt($result['effectiveStart'], $input['category']);
        }

        return $result;
    }

    public function submit(array $actor, array $body, string $key): array
    {
        $input = self::input($body);

        return $this->store->mutate($actor, $key, ['action' => 'submit', 'body' => $body], function () use ($actor, $body, $input) {
            $preview = $this->preview($actor, $input);
            CutiError::check(! $preview['errors'], $preview['errors'][0]['message'] ?? 'Tidak valid.', 422, $preview['errors'][0]['code'] ?? 'INVALID', $preview);
            CutiError::check(($body['fingerprint'] ?? '') === $preview['fingerprint'], 'Perhitungan berubah. Tinjau kembali pengajuan.', 409, 'PREVIEW_CHANGED', $preview);
            CutiError::check(! $preview['adjusted'] || ($body['acceptAdjustment'] ?? false) === true, 'Setujui penyesuaian tanggal sebelum mengirim.');
            $draft = null;
            if (! empty($body['draftId'])) {
                $draft = $this->store->findLeave($actor, $body['draftId']);
                CutiError::check($draft['employeeId'] === $actor['id'] && $draft['status'] === 'DRAFT', 'Draft tidak ditemukan.', 404);
                CutiError::check($draft['version'] === ($body['draftVersion'] ?? null), 'Draft sudah berubah.', 409);
            }
            $id = $draft['id'] ?? (string) Str::uuid();
            $location = collect($this->store->outlets($actor['unit']))->firstWhere('id', CutiStore::outletId($actor));
            CutiError::check($location, 'Outlet karyawan tidak tersedia. Hubungi administrator.');
            $now = LeaveRules::timestamp();
            $leave = [...$input, 'id' => $id, 'number' => 'CT-'.str_replace('-', '', LeaveRules::today()).'-'.strtoupper(substr($id, 0, 6)), 'employeeId' => $actor['id'], 'employeeName' => $actor['name'], 'position' => $actor['position'], 'positionName' => $actor['positionName'] ?? $actor['position'], 'unit' => $actor['unit'], 'outletId' => $location['id'], 'outletName' => $location['name'], 'status' => 'PENDING_SDM', 'days' => $preview['days'], 'effectiveStart' => $preview['effectiveStart'], 'effectiveEnd' => $preview['effectiveEnd'], 'duration' => $preview['duration'], 'submittedAt' => $now, 'createdAt' => $draft['createdAt'] ?? $now, 'confirmation' => 'NOT_CONFIRMED', 'version' => ($draft['version'] ?? 0) + 1, 'calendarVersion' => $this->store->policy($actor['unit'])['calendar']['version'], 'limit' => $preview['quota']['limit'], 'events' => [['at' => $now, 'text' => 'Pengajuan dikirim ke SDM', 'actor' => $actor['name']]]];
            $this->store->saveRequest($leave);
            $this->store->audit($actor, 'SUBMIT', $id);
            $this->store->queueMail($leave, $input['category'] === 'REGULAR' ? 'REMINDER' : 'EMERGENCY', $preview['scheduledAt']);

            return $leave;
        });
    }

    public function act(array $actor, string $id, string $action, array $body, string $key): array
    {
        return $this->store->mutate($actor, $key, ['id' => $id, 'action' => $action, 'body' => $body], function () use ($actor, $id, $action, $body) {
            $leave = $this->store->findLeave($actor, $id);
            CutiError::check(($body['version'] ?? null) === $leave['version'], 'Data sudah berubah. Muat ulang.', 409, 'STALE_VERSION');
            $now = LeaveRules::timestamp();
            $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
            $reviewer = in_array('SDM', $actor['roles'], true) && $actor['id'] !== $leave['employeeId'];
            if ($action === 'withdraw') {
                CutiError::check($leave['status'] === 'PENDING_SDM', 'Pengajuan sudah diputuskan atau ditarik.', 409, 'REQUEST_ALREADY_DECIDED');
                CutiError::check($actor['id'] === $leave['employeeId'], 'Hanya pemohon yang dapat menarik.', 403);
                $leave['status'] = 'WITHDRAWN';
                $text = 'Pengajuan ditarik pemohon';
            } elseif ($action === 'cancel-request') {
                CutiError::check($leave['status'] === 'APPROVED', 'Hanya cuti yang disetujui yang dapat diajukan pembatalan.', 409);
                CutiError::check($actor['id'] === $leave['employeeId'], 'Hanya pemohon yang dapat mengajukan pembatalan.', 403);
                CutiError::check($leave['effectiveEnd'] >= LeaveRules::today(), 'Cuti yang sudah selesai tidak dapat dibatalkan.');
                CutiError::check(($leave['cancellation']['status'] ?? '') !== 'PENDING', 'Permohonan pembatalan sedang menunggu SDM.', 409);
                $this->checkReason($reason);
                $leave['cancellation'] = ['reason' => $reason, 'requestedAt' => $now, 'status' => 'PENDING'];
                $text = 'Karyawan mengajukan pembatalan cuti: '.$reason;
            } elseif ($action === 'cancel-abort') {
                CutiError::check($leave['status'] === 'APPROVED' && ($leave['cancellation']['status'] ?? '') === 'PENDING', 'Tidak ada permohonan pembatalan aktif.', 409);
                CutiError::check($actor['id'] === $leave['employeeId'], 'Hanya pemohon yang dapat menarik permohonan pembatalan.', 403);
                unset($leave['cancellation']);
                $text = 'Permohonan pembatalan cuti ditarik oleh karyawan';
            } elseif ($action === 'cancel-review') {
                CutiError::check($leave['status'] === 'APPROVED' && ($leave['cancellation']['status'] ?? '') === 'PENDING', 'Tidak ada permohonan pembatalan aktif untuk ditinjau.', 409);
                CutiError::check($reviewer, 'Review pembatalan memerlukan SDM lain yang berwenang.', 403);
                CutiError::check(in_array($body['outcome'] ?? '', ['APPROVED', 'REJECTED'], true), 'Keputusan pembatalan tidak valid.');
                if ($body['outcome'] === 'REJECTED') {
                    $this->checkReason($reason, 'Alasan penolakan pembatalan minimal 5 karakter.');
                } else {
                    $leave['status'] = 'WITHDRAWN';
                }
                $leave['cancellation'] = [...$leave['cancellation'], 'status' => $body['outcome'], 'reviewedAt' => $now, 'reviewedBy' => $actor['name'], 'decisionReason' => mb_substr($reason, 0, 2000)];
                $text = ($body['outcome'] === 'APPROVED' ? 'SDM menyetujui pembatalan cuti' : 'SDM menolak permohonan pembatalan cuti').($reason ? ': '.$reason : '');
            } elseif ($action === 'notify-pgs') {
                CutiError::check(in_array('SDM', $actor['roles'], true), 'Hanya SDM yang dapat mengabarkan PGS.', 403);
                CutiError::check($leave['status'] === 'APPROVED' && isset($leave['replacement']), 'Pengajuan harus sudah disetujui dan memiliki penugasan PGS.', 400);
                $channel = is_string($body['channel'] ?? null) && trim($body['channel']) ? trim($body['channel']) : 'WhatsApp';
                CutiError::check(mb_strlen($channel) <= 120, 'Kanal terlalu panjang.');
                $leave['replacement'] = [...$leave['replacement'], 'notifiedAt' => $now, 'notifiedChannel' => $channel, 'notifiedBy' => $actor['name']];
                $text = 'SDM mengabarkan penugasan kepada PGS ('.$leave['replacement']['employeeName'].') via '.$channel;
            } else {
                CutiError::check($leave['status'] === 'PENDING_SDM', 'Pengajuan sudah diputuskan atau ditarik.', 409, 'REQUEST_ALREADY_DECIDED');
                CutiError::check($reviewer, 'Review memerlukan SDM lain yang berwenang.', 403);
                if ($action === 'confirmation') {
                    CutiError::check($leave['category'] === 'REGULAR', 'Konfirmasi hanya untuk cuti reguler.');
                    CutiError::check(in_array($body['result'] ?? '', ['CONFIRMED', 'DECLINED'], true), 'Konfirmasi tidak valid.');
                    CutiError::check(is_string($body['channel'] ?? null) && mb_strlen(trim($body['channel'])) >= 2 && mb_strlen($body['channel']) <= 120, 'Isi kanal konfirmasi.');
                    $leave['confirmation'] = $body['result'];
                    $leave['confirmationChannel'] = $body['channel'];
                    if ($body['result'] === 'DECLINED') {
                        $leave['status'] = 'WITHDRAWN';
                    }
                    $text = $body['result'] === 'CONFIRMED' ? 'Karyawan mengonfirmasi jadi cuti' : 'Karyawan mengonfirmasi tidak jadi cuti';
                } elseif ($action === 'decision') {
                    CutiError::check(in_array($body['outcome'] ?? '', ['APPROVED', 'REJECTED'], true), 'Keputusan tidak valid.');
                    if ($body['outcome'] === 'APPROVED') {
                        CutiError::check($leave['effectiveStart'] >= LeaveRules::today(), 'Tanggal mulai cuti sudah terlewati.');
                        CutiError::check($leave['category'] === 'EMERGENCY' || $leave['confirmation'] === 'CONFIRMED', 'Catat konfirmasi karyawan sebelum menyetujui.');
                        $policy = $this->store->policy($actor['unit']);
                        $input = [...$leave, 'category' => 'EMERGENCY', 'start' => $leave['effectiveStart'], 'end' => $leave['effectiveEnd']];
                        $others = array_filter($this->store->requests(), fn ($r) => $r['id'] !== $id);
                        $checkDates = LeaveRules::evaluate($input, $this->store->employee($leave['employeeId']), $others, $policy['calendar'], $leave['limit'], LeaveRules::today());
                        CutiError::check(! $checkDates['errors'] && $checkDates['days'] === $leave['days'], 'Jadwal atau kuota berubah. Periksa kembali pengajuan.', 409, 'SCHEDULE_CHANGED');
                        $check = $this->replacements->check($leave, $policy);
                        if ($check['rule']['enabled'] ?? false) {
                            CutiError::check(collect($check['candidates'])->contains('available', true), 'Tidak ada PGS yang tersedia sesuai aturan admin. Persetujuan belum dapat dilakukan.', 409, 'NO_REPLACEMENT');
                            $leave['replacementCheckedAt'] = $now;
                            if (! empty($body['replacementId'])) {
                                $candidate = collect($check['candidates'])->first(fn ($c) => $c['id'] === $body['replacementId'] && $c['available']);
                                CutiError::check($candidate, 'Calon PGS sudah tidak tersedia atau tidak memenuhi aturan. Periksa kembali.', 409, 'REPLACEMENT_UNAVAILABLE');
                                $leave['replacement'] = ['employeeId' => $candidate['id'], 'employeeName' => $candidate['name'], 'position' => $candidate['position'], 'positionName' => $candidate['positionName'], 'outletId' => $candidate['outletId'], 'outletName' => $candidate['outletName'], 'phone' => $candidate['phone'], 'email' => $candidate['email'], 'assignedAt' => $now, 'assignedBy' => $actor['name']];
                            }
                        } else {
                            CutiError::check(empty($body['replacementId']), 'Aturan PGS belum aktif untuk posisi ini.');
                        }
                    } else {
                        $this->checkReason($reason, 'Alasan penolakan minimal 5 karakter.');
                    }
                    $leave['status'] = $body['outcome'];
                    $leave['decisionReason'] = mb_substr($reason, 0, 2000);
                    $text = $body['outcome'] === 'APPROVED' ? 'Pengajuan disetujui SDM'.(isset($leave['replacement']) ? ' (PGS: '.$leave['replacement']['employeeName'].')' : '') : 'Pengajuan ditolak SDM';
                } else {
                    throw new CutiError('Aksi tidak ditemukan.', 404);
                }
            }
            $leave['version']++;
            $leave['events'][] = ['at' => $now, 'text' => $text, 'actor' => $actor['name']];
            if ($action === 'decision' && $leave['status'] === 'APPROVED' && isset($leave['replacement'])) {
                $leave['events'][] = ['at' => $now, 'text' => 'Penugasan PGS: '.$leave['replacement']['employeeName'].' ('.$leave['replacement']['position'].')', 'actor' => $actor['name']];
                $this->store->queueMail($leave, 'PGS_ASSIGNED', $now);
            }
            $this->store->saveRequest($leave);
            $this->store->audit($actor, strtoupper($action), $id);
            if (in_array($action, ['decision', 'cancel-review'], true)) {
                $this->store->queueMail($leave, 'DECISION', $now);
                if ($action === 'cancel-review' && ($leave['cancellation']['status'] ?? '') === 'APPROVED' && isset($leave['replacement'])) {
                    $this->store->queueMail($leave, 'PGS_RELEASED', $now);
                }
            }
            if (in_array($action, ['withdraw', 'decision', 'confirmation'], true)) {
                DB::table('mail_jobs')->where('request_id', $id)->where('kind', 'REMINDER')->where('state', 'QUEUED')->update(['state' => 'SKIPPED']);
            }

            return $leave;
        });
    }

    private function checkReason(string $reason, string $message = 'Alasan pembatalan minimal 5 karakter.'): void
    {
        CutiError::check(mb_strlen($reason) >= 5 && mb_strlen($reason) <= 2000, $message);
    }

    public function draft(array $actor, array $body, string $key): array
    {
        $validated = Validator::make($body, ['id' => 'sometimes|string|max:64', 'version' => 'sometimes|integer', 'category' => 'required|in:REGULAR,EMERGENCY', 'subtype' => 'nullable|string|max:120', 'reason' => 'nullable|string|max:2000', 'start' => 'nullable|string|max:10', 'end' => 'nullable|string|max:10', 'email' => 'nullable|string|max:200', 'phone' => 'nullable|string|max:20'])->validate();

        return $this->store->mutate($actor, $key, ['action' => 'draft', 'body' => $body], function () use ($actor, $validated) {
            $old = ! empty($validated['id']) ? $this->store->findLeave($actor, $validated['id']) : null;
            if ($old) {
                CutiError::check($old['status'] === 'DRAFT' && $old['employeeId'] === $actor['id'] && $old['version'] === ($validated['version'] ?? null), 'Draft sudah berubah atau tidak ditemukan.', 409);
            }
            $input = ['category' => $validated['category']];
            foreach (['subtype', 'reason', 'start', 'end', 'email', 'phone'] as $field) {
                $input[$field] = $validated[$field] ?? '';
            }

            return $this->store->saveRequest([...$input, 'id' => $old['id'] ?? (string) Str::uuid(), 'number' => 'Draft', 'employeeId' => $actor['id'], 'employeeName' => $actor['name'], 'unit' => $actor['unit'], 'position' => $actor['position'], 'status' => 'DRAFT', 'days' => [], 'effectiveStart' => '', 'effectiveEnd' => '', 'duration' => 0, 'submittedAt' => '', 'createdAt' => $old['createdAt'] ?? LeaveRules::timestamp(), 'confirmation' => 'NOT_CONFIRMED', 'version' => ($old['version'] ?? 0) + 1, 'calendarVersion' => 0, 'limit' => 0, 'events' => []]);
        });
    }
}
