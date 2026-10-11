<?php

namespace Modules\Schichtplan\src\Livewire;

use App\Models\Group;
use App\Models\MemberFunction;
use Modules\Schichtplan\src\Models\Plan;

/** The self sign-up settings of a plan, shared by "Neuer Plan" and the editor */
trait SignupSettings
{
    public string $signup = 'planner';

    public string $signupUntil = '';

    public bool $needsApproval = false;

    public string $maxShifts = '';

    /** @var list<string> */
    public array $audienceGroups = [];

    /** @var list<string> */
    public array $audienceFunctions = [];

    protected function signupRules(): array
    {
        return [
            'signup' => ['required', 'in:'.implode(',', array_keys(Plan::SIGNUP_MODES))],
            'signupUntil' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'maxShifts' => ['nullable', 'integer', 'min:1', 'max:50'],
            'audienceGroups.*' => ['integer', 'exists:groups,id'],
            'audienceFunctions.*' => ['integer', 'exists:member_functions,id'],
        ];
    }

    protected function signupData(): array
    {
        return [
            'signup' => $this->signup, 'signup_until' => $this->signup === 'self' ? PlanTimes::parseLocal($this->signupUntil) : null,
            'needs_approval' => $this->needsApproval, 'max_shifts' => $this->signup === 'self' ? $this->maxShifts : null,
            'audience_groups' => $this->audienceGroups, 'audience_functions' => $this->audienceFunctions,
        ];
    }

    protected function fillSignup(Plan $plan): void
    {
        $this->signup = $plan->signup;
        $this->signupUntil = PlanTimes::local($plan->signup_until, 'Y-m-d\TH:i');
        $this->needsApproval = $plan->needs_approval;
        $this->maxShifts = (string) ($plan->max_shifts ?? '');
        $this->audienceGroups = array_map('strval', $plan->audience_groups ?? []);
        $this->audienceFunctions = array_map('strval', $plan->audience_functions ?? []);
    }

    protected function signupOptions(): array
    {
        return [
            'audienceGroupOptions' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'audienceFunctionOptions' => MemberFunction::with('parent')->orderBy('sort_order')->orderBy('name')->get()
                ->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()])->sort(),
        ];
    }
}
