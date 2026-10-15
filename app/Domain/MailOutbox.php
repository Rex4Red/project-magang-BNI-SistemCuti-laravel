<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class MailOutbox
{
    public function __construct(private CutiStore $store) {}

    public function processOne(): bool
    {
        $job = DB::transaction(function () {
            $job = DB::table('mail_jobs')->where(fn ($query) => $query->where(fn ($queued) => $queued->where('state', 'QUEUED')->where('due_at', '<=', now('UTC')))->orWhere(fn ($expired) => $expired->where('state', 'PROCESSING')->where('lease_until', '<', now('UTC'))))->orderBy('due_at')->lockForUpdate()->first();
            if ($job) {
                DB::table('mail_jobs')->where('id', $job->id)->update(['state' => 'PROCESSING', 'lease_until' => now('UTC')->addMinutes(2)]);
            }

            return $job;
        }, 3);
        if (! $job) {
            return false;
        }
        try {
            $row = DB::table('requests')->where('id', $job->request_id)->first();
            $leave = $row ? CutiStore::decode($row->data) : null;
            if (! $leave || ($job->kind === 'REMINDER' && ($leave['status'] !== 'PENDING_SDM' || $leave['confirmation'] !== 'NOT_CONFIRMED')) || ($job->kind === 'EMERGENCY' && $leave['status'] !== 'PENDING_SDM')) {
                DB::table('mail_jobs')->where('id', $job->id)->update(['state' => 'SKIPPED', 'lease_until' => null]);

                return true;
            }
            if (str_starts_with($job->kind, 'PGS_')) {
                $employee = $this->store->employee($leave['replacement']['employeeId'] ?? '');
                $recipients = ! empty($employee['email']) ? [$employee['email']] : [];
                CutiError::check($recipients, 'NO_PGS_RECIPIENT');
            } elseif ($job->kind === 'DECISION') {
                $recipients = [$leave['email']];
            } else {
                $recipients = array_column(array_filter($this->store->employees($job->unit_id), fn ($e) => $e['active'] && in_array('SDM', $e['roles'], true)), 'email');
            }
            CutiError::check($recipients, 'NO_SDM_RECIPIENT');
            $status = ($leave['cancellation']['status'] ?? '') === 'APPROVED' ? 'Pembatalan disetujui' : (($leave['cancellation']['status'] ?? '') === 'REJECTED' ? 'Pembatalan ditolak' : (['APPROVED' => 'Disetujui', 'REJECTED' => 'Ditolak', 'WITHDRAWN' => 'Ditarik'][$leave['status']] ?? 'Perlu review SDM'));
            $title = match ($job->kind) {
                'PGS_ASSIGNED' => 'Penugasan PGS',
                'PGS_RELEASED' => 'Pembatalan tugas PGS',
                'DECISION' => $status,
                default => 'Perlu review SDM',
            };
            $subject = '[BNI Cuti] '.$leave['number'].' · '.$title;
            $body = $title."\nPengajuan: ".$leave['number']."\nKaryawan: ".$leave['employeeName']."\nTanggal efektif: ".$leave['effectiveStart'].' s.d. '.$leave['effectiveEnd']."\n".rtrim(config('app.url'), '/').'/?request='.rawurlencode($leave['id']);
            $smtp = config('cuti.mail_mode') === 'smtp';
            foreach (array_unique($recipients) as $recipient) {
                DB::table('mail_deliveries')->insertOrIgnore(['id' => (string) Str::uuid(), 'job_id' => $job->id, 'recipient' => $recipient, 'state' => 'QUEUED', 'subject' => $subject, 'body' => $body]);
                $delivery = DB::table('mail_deliveries')->where('job_id', $job->id)->where('recipient', $recipient)->first();
                if (in_array($delivery->state, ['SENT', 'CAPTURED'], true)) {
                    continue;
                }
                if ($smtp) {
                    CutiError::check(config('mail.mailers.smtp.host') && config('mail.from.address'), 'SMTP_NOT_CONFIGURED');
                    Mail::mailer('smtp')->raw($delivery->body, function ($message) use ($recipient, $delivery) {
                        $message->to($recipient)->subject($delivery->subject);
                        $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $delivery->id.'@cuti.local');
                    });
                }
                DB::table('mail_deliveries')->where('id', $delivery->id)->update(['state' => $smtp ? 'SENT' : 'CAPTURED']);
            }
            DB::table('mail_jobs')->where('id', $job->id)->update(['state' => $smtp ? 'SENT' : 'CAPTURED', 'lease_until' => null, 'last_error' => null]);
        } catch (Throwable $error) {
            $attempt = $job->attempts + 1;
            $code = in_array($error->getMessage(), ['NO_SDM_RECIPIENT', 'NO_PGS_RECIPIENT', 'SMTP_NOT_CONFIGURED'], true) ? $error->getMessage() : 'DELIVERY_FAILED';
            DB::table('mail_jobs')->where('id', $job->id)->update(['state' => $attempt >= 5 ? 'FAILED' : 'QUEUED', 'attempts' => $attempt, 'due_at' => now('UTC')->addMinutes([1, 5, 15, 60, 360][min($attempt - 1, 4)]), 'last_error' => $code, 'lease_until' => null]);
        }

        return true;
    }
}
