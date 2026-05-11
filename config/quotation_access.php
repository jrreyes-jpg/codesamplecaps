<?php

require_once __DIR__ . '/auth_middleware.php';

if (!function_exists('quotation_permissions_for_role')) {
    function quotation_permissions_for_role(?string $role = null): array
    {
        $role = $role ?? (string)current_user_role();

        $matrix = [
            'engineer' => [
                'create' => true,
                'edit_draft' => true,
                'submit_for_review' => true,
                'submit_for_approval' => true,
                'review' => false,
                'adjust_pricing' => false,
                'approve' => false,
                'send_to_client' => false,
                'respond' => false,
            ],
            'foreman' => [
                'create' => false,
                'edit_draft' => false,
                'submit_for_review' => false,
                'submit_for_approval' => false,
                'review' => true,
                'adjust_pricing' => false,
                'approve' => false,
                'send_to_client' => false,
                'respond' => false,
            ],
            'super_admin' => [
                'create' => false,
                'edit_draft' => false,
                'submit_for_review' => false,
                'submit_for_approval' => false,
                'review' => true,
                'adjust_pricing' => true,
                'approve' => true,
                'send_to_client' => true,
                'respond' => false,
            ],
            'client' => [
                'create' => false,
                'edit_draft' => false,
                'submit_for_review' => false,
                'submit_for_approval' => false,
                'review' => false,
                'adjust_pricing' => false,
                'approve' => false,
                'send_to_client' => false,
                'respond' => true,
            ],
        ];

        return $matrix[$role] ?? [
            'create' => false,
            'edit_draft' => false,
            'submit_for_review' => false,
            'submit_for_approval' => false,
            'review' => false,
            'adjust_pricing' => false,
            'approve' => false,
            'send_to_client' => false,
            'respond' => false,
        ];
    }
}

if (!function_exists('quotation_user_can')) {
    function quotation_user_can(string $permission, ?string $role = null): bool
    {
        $permissions = quotation_permissions_for_role($role);
        return (bool)($permissions[$permission] ?? false);
    }
}

if (!function_exists('quotation_allowed_transitions')) {
    function quotation_allowed_transitions(): array
    {
        return [
            'draft' => ['under_review'],
            'under_review' => ['draft', 'for_approval'],
            'for_approval' => ['under_review', 'approved'],
            'approved' => ['sent'],
            'sent' => ['accepted', 'rejected'],
            'accepted' => [],
            'rejected' => [],
        ];
    }
}

if (!function_exists('quotation_transition_is_allowed')) {
    function quotation_transition_is_allowed(string $fromStatus, string $toStatus): bool
    {
        $allowedTransitions = quotation_allowed_transitions();
        return in_array($toStatus, $allowedTransitions[$fromStatus] ?? [], true);
    }
}
