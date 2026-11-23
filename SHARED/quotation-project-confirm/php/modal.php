<?php
// Isang confirmation modal para sa lahat ng Create Project button.
if (!function_exists('render_quotation_project_confirm_modal')) {
    function render_quotation_project_confirm_modal(): void
    {
        ?>
        <div class="shared-project-confirm" data-project-confirm-modal hidden>
            <div class="shared-project-confirm__backdrop admin-modal-backdrop" data-project-confirm-cancel></div>
            <section class="shared-project-confirm__card" role="dialog" aria-modal="true" aria-labelledby="sharedProjectConfirmTitle">
                <header class="shared-project-confirm__head">
                    <h2 id="sharedProjectConfirmTitle">Create Project?</h2>
                    <button type="button" class="shared-project-confirm__close" data-project-confirm-cancel aria-label="Close create project confirmation">&times;</button>
                </header>
                <p>Create a project from this accepted quotation. The client, scope, items, and total will be copied.</p>
                <div class="shared-project-confirm__actions">
                    <button type="button" class="button-secondary" data-project-confirm-cancel>Cancel</button>
                    <button type="button" class="button-primary" data-project-confirm-submit>Continue to Project Form</button>
                </div>
            </section>
        </div>
        <?php
    }
}
