<?php
// Walk-in form ng Admin Inquiry page.
?>
<section class="project-create-modal<?php echo $openWalkInInquiry ? ' is-open' : ''; ?>" data-walk-in-inquiry-panel aria-hidden="<?php echo $openWalkInInquiry ? 'false' : 'true'; ?>">
    <div class="form-panel walk-in-inquiry-panel" role="dialog" aria-modal="true" aria-labelledby="walk-in-inquiry-title">
        <div class="project-create-header">
            <div>
                <h6 class="section-title-inline project-create-title" id="walk-in-inquiry-title">Create Walk-in Inquiry</h6>
                <p class="walk-in-inquiry-note">Save the request first. Confirm the final date later.</p>
            </div>
            <button type="button" class="project-modal-close" data-walk-in-inquiry-close aria-label="Close walk-in inquiry">&times;</button>
        </div>

        <form method="POST" action="/codesamplecaps/ADMIN/sidebar/projects/php/create_walk_in_inquiry.php" class="walk-in-inquiry-form" data-walk-in-inquiry-form data-has-server-input="<?php echo !empty($walkInOldInput) ? '1' : '0'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($walkInCsrfToken, ENT_QUOTES); ?>">
            <input type="hidden" name="next_action" value="pending" data-walk-in-next-action>
            <div class="walk-in-inquiry-grid">
                <div class="input-group">
                    <label for="walk_in_client_name">Contact Person's Full Name <span class="required-indicator">*</span></label>
                    <input id="walk_in_client_name" name="client_name" type="text" maxlength="150" value="<?php echo htmlspecialchars((string)($walkInOldInput['client_name'] ?? ''), ENT_QUOTES); ?>" placeholder="e.g., Juan Dela Cruz" data-walk-in-client-name required>
                    <small class="walk-in-field-error" data-walk-in-name-error></small>
                </div>

                <div class="input-group">
                    <label for="walk_in_company_name">Company / Organization Name <span class="optional-indicator">(Optional)</span></label>
                    <input id="walk_in_company_name" name="company_name" type="text" maxlength="150" value="<?php echo htmlspecialchars((string)($walkInOldInput['company_name'] ?? ''), ENT_QUOTES); ?>" placeholder="Leave blank for an individual client">
                    <small class="walk-in-field-help">Blank will be shown as Individual Client.</small>
                </div>

                <div class="input-group">
                    <label for="walk_in_email">Email Address <span class="required-indicator">*</span></label>
                    <input id="walk_in_email" name="email" type="email" maxlength="190" autocomplete="email" value="<?php echo htmlspecialchars((string)($walkInOldInput['email'] ?? ''), ENT_QUOTES); ?>" placeholder="name@example.com" data-walk-in-email required>
                    <small class="walk-in-field-error" data-walk-in-email-error></small>
                    <span class="walk-in-email-tools">
                        <button type="button" class="walk-in-verify-email-button" data-walk-in-send-otp>Verify Email</button>
                        <small class="walk-in-email-status" data-walk-in-email-status>Email not verified</small>
                    </span>
                </div>

                <div class="input-group">
                    <label for="walk_in_contact_no">Contact Number <span class="required-indicator">*</span></label>
                    <input id="walk_in_contact_no" name="contact_no" type="tel" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" placeholder="09xxxxxxxxx" value="<?php echo htmlspecialchars((string)($walkInOldInput['contact_no'] ?? '09'), ENT_QUOTES); ?>" data-walk-in-contact-number required>
                    <small class="walk-in-field-error" data-walk-in-contact-error></small>
                    <small class="walk-in-field-help">Enter 11 digits starting with 09.</small>
                </div>

                <div class="input-group input-group-wide">
                    <label for="walk_in_service">Service Category <span class="required-indicator">*</span></label>
                    <select id="walk_in_service" name="service_category" required>
                        <option value="" disabled<?php echo empty($walkInOldInput['service_category']) ? ' selected' : ''; ?>>Select service</option>
                        <?php foreach (['New Automation Installation', 'System Upgrade/Retrofitting', 'Preventive Maintenance', 'Emergency Troubleshooting', 'Other Service'] as $service): ?>
                            <option value="<?php echo htmlspecialchars($service, ENT_QUOTES); ?>" <?php echo (string)($walkInOldInput['service_category'] ?? '') === $service ? 'selected' : ''; ?>><?php echo htmlspecialchars($service); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group" data-walk-in-other-service hidden>
                    <label for="walk_in_other_service">Other Service Details <span class="required-indicator">*</span></label>
                    <input id="walk_in_other_service" name="other_service_details" type="text" minlength="3" maxlength="250" value="<?php echo htmlspecialchars((string)($walkInOldInput['other_service_details'] ?? ''), ENT_QUOTES); ?>" placeholder="Briefly describe the service" disabled>
                    <small class="walk-in-field-error" data-walk-in-other-error></small>
                </div>

                <div class="input-group input-group-wide">
                    <label for="walk_in_estimated_budget">Estimated Budget <span class="optional-indicator">(Optional)</span></label>
                    <select id="walk_in_estimated_budget" name="estimated_budget">
                        <option value="" disabled<?php echo empty($walkInOldInput['estimated_budget']) ? ' selected' : ''; ?>>Select budget range</option>
                        <?php foreach (inquiry_budget_options() as $budgetValue => $budgetLabel): ?>
                            <option value="<?php echo htmlspecialchars($budgetValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string)($walkInOldInput['estimated_budget'] ?? '') === $budgetValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($budgetLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="walk-in-field-help">Guide only. Final cost may change after inspection.</small>
                </div>

                <div class="input-group">
                    <label for="walk_in_region">Region <span class="required-indicator">*</span></label>
                    <span class="walk-in-combobox" data-walk-in-combobox>
                        <input id="walk_in_region" name="region" type="text" autocomplete="off" placeholder="Loading regions..." data-walk-in-region data-initial-value="<?php echo htmlspecialchars((string)($walkInOldInput['region'] ?? ''), ENT_QUOTES); ?>" required>
                        <button type="button" class="walk-in-combobox__toggle" data-walk-in-combobox-toggle aria-label="Show region options"></button>
                        <span class="walk-in-combobox__list" data-walk-in-combobox-list></span>
                    </span>
                </div>

                <div class="input-group">
                    <label for="walk_in_area">Province / Area <span class="required-indicator">*</span></label>
                    <span class="walk-in-combobox" data-walk-in-combobox>
                        <input id="walk_in_area" name="location_area" type="text" autocomplete="off" placeholder="Select region first" data-walk-in-area data-initial-value="<?php echo htmlspecialchars((string)($walkInOldInput['location_area'] ?? ''), ENT_QUOTES); ?>" disabled required>
                        <button type="button" class="walk-in-combobox__toggle" data-walk-in-combobox-toggle aria-label="Show province or area options"></button>
                        <span class="walk-in-combobox__list" data-walk-in-combobox-list></span>
                    </span>
                </div>

                <div class="input-group">
                    <label for="walk_in_city">City / Municipality <span class="required-indicator">*</span></label>
                    <span class="walk-in-combobox" data-walk-in-combobox>
                        <input id="walk_in_city" name="city_municipality" type="text" autocomplete="off" placeholder="Select province / area first" data-walk-in-city data-initial-value="<?php echo htmlspecialchars((string)($walkInOldInput['city_municipality'] ?? ''), ENT_QUOTES); ?>" disabled required>
                        <button type="button" class="walk-in-combobox__toggle" data-walk-in-combobox-toggle aria-label="Show city options"></button>
                        <span class="walk-in-combobox__list" data-walk-in-combobox-list></span>
                    </span>
                </div>

                <div class="input-group">
                    <label for="walk_in_barangay">Barangay <span class="required-indicator">*</span></label>
                    <span class="walk-in-combobox" data-walk-in-combobox>
                        <input id="walk_in_barangay" name="barangay" type="text" autocomplete="off" placeholder="Select city first" data-walk-in-barangay data-initial-value="<?php echo htmlspecialchars((string)($walkInOldInput['barangay'] ?? ''), ENT_QUOTES); ?>" disabled required>
                        <button type="button" class="walk-in-combobox__toggle" data-walk-in-combobox-toggle aria-label="Show barangay options"></button>
                        <span class="walk-in-combobox__list" data-walk-in-combobox-list></span>
                    </span>
                </div>

                <div class="input-group input-group-wide">
                    <label for="walk_in_site_address">Site Address Details <span class="required-indicator">*</span></label>
                    <textarea id="walk_in_site_address" name="site_address" rows="2" minlength="5" maxlength="500" placeholder="House/building no., street, subdivision or sitio" required><?php echo htmlspecialchars((string)($walkInOldInput['site_address'] ?? '')); ?></textarea>
                </div>

                <div class="input-group input-group-wide">
                    <label for="walk_in_description">Project Description <span class="required-indicator">*</span></label>
                    <textarea id="walk_in_description" name="description" rows="3" minlength="3" maxlength="2000" placeholder="Tell us the equipment, issue, or work needed" data-walk-in-description required><?php echo htmlspecialchars((string)($walkInOldInput['description'] ?? '')); ?></textarea>
                    <small class="walk-in-field-error" data-walk-in-description-error></small>
                </div>

                <div class="input-group input-group-wide">
                    <label for="walk_in_preferred_date">Preferred Inspection Date <span class="required-indicator">*</span></label>
                    <input id="walk_in_preferred_date" name="preferred_inspection_date" type="date" min="<?php echo htmlspecialchars($walkInScheduleRange['min'], ENT_QUOTES); ?>" max="<?php echo htmlspecialchars($walkInScheduleRange['max'], ENT_QUOTES); ?>" value="<?php echo htmlspecialchars((string)($walkInOldInput['preferred_inspection_date'] ?? ''), ENT_QUOTES); ?>" data-walk-in-date required>
                    <small class="walk-in-field-help">Today up to 30 days ahead. Admin will confirm the final date.</small>
                </div>

                <div class="input-group input-group-wide">
                    <label for="walk_in_engineer">Preferred Engineer <span class="optional-indicator">(Optional)</span></label>
                    <select id="walk_in_engineer" name="preferred_engineer_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($engineers as $engineer): ?>
                            <?php if ((string)($engineer['role'] ?? '') !== 'engineer') continue; ?>
                            <option value="<?php echo (int)$engineer['id']; ?>" <?php echo (int)($walkInOldInput['preferred_engineer_id'] ?? 0) === (int)$engineer['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$engineer['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="walk-in-field-help" data-walk-in-engineer-help>Choose an Engineer to contact, or leave it Unassigned.</small>
                </div>
            </div>

            <div class="form-actions walk-in-inquiry-actions">
                <button type="button" class="btn-secondary walk-in-clear-button" data-walk-in-clear>Clear Form</button>
                <button type="button" class="btn-secondary" data-walk-in-inquiry-close>Cancel</button>
                <button type="submit" class="btn-primary" value="pending" data-walk-in-submit-action>Save Walk-in Inquiry</button>
            </div>
        </form>
    </div>
</section>

<section class="walk-in-otp-modal" data-walk-in-otp-modal aria-hidden="true">
    <div class="walk-in-otp-card" role="dialog" aria-modal="true" aria-labelledby="walk-in-otp-title">
        <div class="walk-in-otp-loading" data-walk-in-otp-loading aria-live="polite">
            <span class="walk-in-otp-spinner" aria-hidden="true"></span>
            <h3>Sending code...</h3>
            <p>Please wait. Do not close this page.</p>
        </div>
        <div class="walk-in-otp-content" data-walk-in-otp-content>
            <div class="walk-in-otp-heading">
                <span class="walk-in-otp-icon" aria-hidden="true">&#9993;</span>
                <h3 id="walk-in-otp-title">Verify Client Email</h3>
                <p>Enter the 6-digit code sent to</p>
                <strong class="walk-in-otp-email" data-walk-in-otp-email></strong>
            </div>
            <label class="walk-in-otp-label" for="walk_in_otp_code">Verification Code</label>
            <input class="walk-in-otp-code" id="walk_in_otp_code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" aria-describedby="walk-in-otp-help walk-in-otp-error" data-walk-in-otp-code>
            <small class="walk-in-field-error walk-in-otp-message" id="walk-in-otp-error" data-walk-in-otp-error></small>
            <p class="walk-in-otp-timer" data-walk-in-otp-timer>Code expires in 03:00</p>
            <div class="walk-in-otp-resend">
                <button type="button" class="walk-in-otp-resend-button" data-walk-in-otp-resend disabled>Resend Code (01:00)</button>
                <p id="walk-in-otp-help">Did not get the code? Check Spam or Junk. You can resend when the timer ends.</p>
            </div>
            <div class="walk-in-confirm-actions">
                <button type="button" class="btn-secondary" data-walk-in-otp-close>Close</button>
                <button type="button" class="btn-primary" data-walk-in-otp-verify>Verify Code</button>
            </div>
        </div>
    </div>
</section>

<section class="walk-in-confirm-modal" data-walk-in-confirm-modal aria-hidden="true">
    <div class="walk-in-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="walk-in-confirm-title" aria-describedby="walk-in-confirm-message">
        <h3 id="walk-in-confirm-title" data-walk-in-confirm-title>Clear this form?</h3>
        <p id="walk-in-confirm-message" data-walk-in-confirm-message>All entered details and the saved draft will be removed.</p>
        <div class="walk-in-confirm-actions">
            <button type="button" class="btn-secondary" data-walk-in-confirm-cancel>Keep Editing</button>
            <button type="button" class="btn-danger" data-walk-in-confirm-accept>Clear Form</button>
        </div>
    </div>
</section>

<section class="walk-in-save-modal" data-walk-in-save-modal aria-hidden="true">
    <div class="walk-in-save-card" role="alertdialog" aria-modal="true" aria-labelledby="walk-in-save-title">
        <span class="walk-in-otp-spinner" aria-hidden="true"></span>
        <h3 id="walk-in-save-title" tabindex="-1" data-walk-in-save-title>Saving inquiry...</h3>
        <p data-walk-in-save-message>Please wait. Do not close this page.</p>
    </div>
</section>
