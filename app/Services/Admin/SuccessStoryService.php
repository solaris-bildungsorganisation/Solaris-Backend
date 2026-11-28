<?php

namespace App\Services\Admin;

use App\Models\SuccessStory;
use App\Models\User;
use App\Policies\ScopeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuccessStoryService
{
    /** حقول لا يجوز أخذ قيمها من العميل؛ تضبطها الخدمة فقط. */
    private const SERVER_MANAGED_FIELDS = [
        'status',
        'published_at',
        'reviewed_by',
        'created_by',
        'consent_recorded_at',
        'consent_recorded_by',
        'consent_status',
    ];

    private const CONSENT_VALUES = ['granted', 'not_required', 'withdrawn'];

    private const PUBLISHABLE_CONSENT = ['granted', 'not_required'];

    public function create(array $data, User $actor): SuccessStory
    {
        $this->rejectServerManagedFields($data);

        return DB::transaction(function () use ($data, $actor): SuccessStory {
            $story = new SuccessStory();
            $story->fill($data);
            $story->created_by = $actor->getKey();
            $story->status = 'draft';
            $story->consent_status = 'pending';

            $this->ensureScope($actor, $story, 'success_stories.manage');

            $story->save();

            $this->audit(
                $actor,
                $story,
                'created',
                null,
                'draft',
                null,
                'pending',
            );

            return $story->refresh();
        });
    }

    public function update(SuccessStory $story, array $data, User $actor): SuccessStory
    {
        $this->rejectServerManagedFields($data);

        return DB::transaction(function () use ($story, $data, $actor): SuccessStory {
            $locked = SuccessStory::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $candidate = clone $locked;
            $candidate->fill($data);

            $this->assertConsentCombination($candidate);
            $this->ensureScope($actor, $locked, 'success_stories.manage');
            $this->ensureScope($actor, $candidate, 'success_stories.manage');

            $oldStatus = $locked->status;
            $oldConsent = $locked->consent_status;

            $locked->fill($data);
            $locked->save();

            $this->audit(
                $actor,
                $locked,
                'updated',
                $oldStatus,
                $locked->status,
                $oldConsent,
                $locked->consent_status,
            );

            return $locked->refresh();
        });
    }

    public function recordConsent(SuccessStory $story, string $consent, User $actor): SuccessStory
    {
        if (! in_array($consent, self::CONSENT_VALUES, true)) {
            throw ValidationException::withMessages([
                'consent_status' => 'قيمة الموافقة غير صحيحة.',
            ]);
        }

        return DB::transaction(function () use ($story, $consent, $actor): SuccessStory {
            $locked = SuccessStory::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureScope($actor, $locked, 'success_stories.manage');

            if ($consent === 'not_required' && $locked->subject_user_id !== null) {
                throw ValidationException::withMessages([
                    'consent_status' => 'لا يمكن اختيار not_required عند وجود subject_user_id.',
                ]);
            }

            $oldConsent = $locked->consent_status;
            $oldStatus = $locked->status;

            $locked->consent_status = $consent;
            $locked->consent_recorded_at = now();
            $locked->consent_recorded_by = $actor->getKey();

            if ($consent === 'withdrawn' && $oldStatus === 'published') {
                $locked->status = 'archived';
            }

            $locked->save();

            // إذا فشل activity log سترجع DB::transaction التعديل كله.
            $this->audit(
                $actor,
                $locked,
                'consent_changed',
                $oldStatus,
                $locked->status,
                $oldConsent,
                $consent,
            );

            return $locked->refresh();
        });
    }

    public function submit(SuccessStory $story, User $actor): SuccessStory
    {
        return DB::transaction(function () use ($story, $actor): SuccessStory {
            $locked = SuccessStory::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureScope($actor, $locked, 'success_stories.manage');

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'يمكن إرسال المسودة فقط للمراجعة.',
                ]);
            }

            $oldStatus = $locked->status;
            $oldConsent = $locked->consent_status;
            $locked->status = 'in_review';
            $locked->save();

            $this->audit(
                $actor,
                $locked,
                'status_changed',
                $oldStatus,
                $locked->status,
                $oldConsent,
                $locked->consent_status,
            );

            return $locked->refresh();
        });
    }

    public function publish(SuccessStory $story, User $actor): SuccessStory
    {
        return DB::transaction(function () use ($story, $actor): SuccessStory {
            $locked = SuccessStory::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureScope($actor, $locked, 'success_stories.publish');

            if ($locked->status !== 'in_review') {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن نشر قصة ليست قيد المراجعة.',
                ]);
            }

            if (! in_array($locked->consent_status, self::PUBLISHABLE_CONSENT, true)) {
                throw ValidationException::withMessages([
                    'consent_status' => 'لا يمكن النشر دون موافقة granted أو not_required.',
                ]);
            }

            if ($locked->consent_status === 'not_required' && $locked->subject_user_id !== null) {
                throw ValidationException::withMessages([
                    'consent_status' => 'not_required غير مسموحة مع subject_user_id.',
                ]);
            }

            if ((int) $locked->created_by === (int) $actor->getKey()) {
                abort(403, 'لا يجوز لمنشئ القصة نشرها بنفسه.');
            }

            $oldStatus = $locked->status;
            $oldConsent = $locked->consent_status;
            $locked->status = 'published';
            $locked->published_at = now();
            $locked->reviewed_by = $actor->getKey();
            $locked->save();

            $this->audit(
                $actor,
                $locked,
                'status_changed',
                $oldStatus,
                $locked->status,
                $oldConsent,
                $locked->consent_status,
            );

            return $locked->refresh();
        });
    }

    public function archive(SuccessStory $story, User $actor): SuccessStory
    {
        return DB::transaction(function () use ($story, $actor): SuccessStory {
            $locked = SuccessStory::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureScope($actor, $locked, 'success_stories.publish');

            if ($locked->status === 'archived') {
                throw ValidationException::withMessages([
                    'status' => 'القصة مؤرشفة بالفعل.',
                ]);
            }

            $oldStatus = $locked->status;
            $oldConsent = $locked->consent_status;
            $locked->status = 'archived';
            $locked->save();

            $this->audit(
                $actor,
                $locked,
                'status_changed',
                $oldStatus,
                $locked->status,
                $oldConsent,
                $locked->consent_status,
            );

            return $locked->refresh();
        });
    }

    /** الحذف المنطقي المستخدم من destroy في SuccessStoryController. */
    public function delete(SuccessStory $story, User $actor): void
    {
        DB::transaction(function () use ($story, $actor): void {
            $locked = SuccessStory::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureScope($actor, $locked, 'success_stories.manage');

            $status = $locked->status;
            $consent = $locked->consent_status;

            $this->audit(
                $actor,
                $locked,
                'deleted',
                $status,
                $status,
                $consent,
                $consent,
            );

            $locked->delete();
        });
    }

    private function ensureScope(User $actor, SuccessStory $story, string $permission): void
    {
        if (! ScopeGuard::covers($actor, $story, $permission)) {
            abort(404);
        }
    }

    private function assertConsentCombination(SuccessStory $story): void
    {
        if ($story->consent_status === 'not_required' && $story->subject_user_id !== null) {
            throw ValidationException::withMessages([
                'subject_user_id' => 'لا يمكن ربط صاحب قصة عندما تكون الموافقة not_required.',
            ]);
        }
    }

    private function rejectServerManagedFields(array $data): void
    {
        $provided = array_intersect(array_keys($data), self::SERVER_MANAGED_FIELDS);

        if ($provided === []) {
            return;
        }

        $errors = [];
        foreach ($provided as $field) {
            $errors[$field] = 'هذا الحقل يحدده النظام ولا يقبل من العميل.';
        }

        throw ValidationException::withMessages($errors);
    }

    /** لا يسجل نص القصة أو بيانات صاحبها أو أسماء الحقول المعدلة. */
    private function audit(
        User $actor,
        SuccessStory $story,
        string $event,
        ?string $statusFrom,
        ?string $statusTo,
        ?string $consentFrom,
        ?string $consentTo,
    ): void {
        activity('admin')
            ->causedBy($actor)
            ->performedOn($story)
            ->event($event)
            ->withProperties([
                'story_uuid' => $story->uuid,
                'status_from' => $statusFrom,
                'status_to' => $statusTo,
                'consent_from' => $consentFrom,
                'consent_to' => $consentTo,
            ])
            ->log('success story '.$event);
    }
}
