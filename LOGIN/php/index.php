<?php
session_start();
require_once __DIR__ . '/../../config/service_areas.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/service_barangays.php';

$serviceAreas = service_area_allowed_locations();
$serviceBarangays = service_barangays_grouped($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edge Automation Technology Services, Co. - Professional engineering automation and technology solutions">
    <title>Edge Automation Technology Services, Co.</title>
    <link rel="stylesheet" href="../css/loader.css">
    <link rel="stylesheet" href="../css/index.css">
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">

</head>
<body>
    <a href="#home" class="skip-link">Skip to content</a>
    <div class="page-loader" id="pageLoader" role="status" aria-live="polite" aria-label="Loading page">
        <div class="page-loader__content">
            <div class="page-loader__scene" aria-hidden="true">
                <div class="page-loader__floor"></div>
                <div class="page-loader__orbit page-loader__orbit--horizontal"></div>
                <div class="page-loader__orbit page-loader__orbit--vertical"></div>
                <div class="page-loader__orbit page-loader__orbit--tilted"></div>
                <div class="page-loader__cube">
                    <span class="page-loader__cube-face page-loader__cube-face--front"></span>
                    <span class="page-loader__cube-face page-loader__cube-face--back"></span>
                    <span class="page-loader__cube-face page-loader__cube-face--right"></span>
                    <span class="page-loader__cube-face page-loader__cube-face--left"></span>
                    <span class="page-loader__cube-face page-loader__cube-face--top"></span>
                    <span class="page-loader__cube-face page-loader__cube-face--bottom"></span>
                </div>
                <div class="page-loader__logo-disk">
                    <img src="../../IMAGES/edge.jpg" alt="" class="page-loader__logo">
                </div>
                <span class="page-loader__spark page-loader__spark--one"></span>
                <span class="page-loader__spark page-loader__spark--two"></span>
                <span class="page-loader__spark page-loader__spark--three"></span>
            </div>
            <div class="page-loader__meta">
                <span class="page-loader__text">Edge Automation Technology Services, Co.</span>
                <span class="page-loader__rail" aria-hidden="true">
                    <span class="page-loader__rail-fill"></span>
                </span>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="#home" class="nav-logo" aria-label="Edge Automation home">
                <img src="../../IMAGES/edge.jpg" alt="Edge Automation logo" class="logo-img" decoding="async">
                <span class="logo-text">EDGE AUTOMATION</span>
            </a>
            <ul class="nav-menu" id="primaryNav">
                <li class="mobile-menu-brand" aria-hidden="true">
                    <img src="../../IMAGES/edge.jpg" alt="" loading="lazy" decoding="async">
                    <span>Edge Automation</span>
                </li>
                <li><a href="#home" class="nav-link">Home</a></li>
                <li><a href="#about" class="nav-link">About</a></li>
                <li><a href="#services" class="nav-link">Services</a></li>
                <li><a href="#projects" class="nav-link">Projects</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
                <?php if (empty($_SESSION['user_id'])): ?>
                    <li class="mobile-only"><a href="login.php" class="nav-link btn btn-primary">Login</a></li>
                <?php endif; ?>
            </ul>

           <div class="nav-actions">
        <a href="login.php" class="btn btn-primary">Login</a>
</div>
            <button class="hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="primaryNav">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </nav>

    <main>
    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-content">
            <span class="hero-kicker">Edge Automation Technology Services, Co.</span>
            <h1 class="hero-title">Industrial Automation & Engineering Solutions</h1>
            <p class="hero-subtitle">Electrical, mechanical, controls, and utility systems built for reliable plant operations.</p>
            <div class="cta-buttons">
                <button class="btn btn-primary" id="consultBtn">Request Consultation</button>
                <a href="#services" class="btn btn-secondary">View Services</a>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="about">
        <div class="container">
            <h2 class="section-title">About Us</h2>
            <div class="about-content">
                <p class="about-text">
                    Edge Automation Technology Services, Co. supports industrial plants and facilities through practical electrical, mechanical, automation, and utility engineering work.
                </p>
                <p class="about-text">
                    Our team focuses on field-ready execution: troubleshooting, installation, commissioning, preventive maintenance, and system improvements that help keep operations reliable.
                </p>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services" class="services">
        <div class="container">
            <h2 class="section-title">Our Services</h2>
            <p class="services-intro">
                We engineer field-ready systems that reduce downtime, improve plant visibility, and keep critical operations running with confidence.
            </p>
            <div class="services-grid">
                <article class="service-card">
                    <span class="service-tag">Plant Utilities</span>
                    <div class="service-icon">&#9881;</div>
                    <h3>Mechanical Engineering</h3>
                    <p class="service-summary">Utility, HVAC, fire protection, and maintenance support for facilities.</p>
                    <ul class="service-list">
                        <li>Utility Systems (Air, Water, Steam)</li>
                        <li>Fire Protection Systems</li>
                        <li>Diesel Generator Systems</li>
                        <li>Cleanroom Systems</li>
                        <li>HVAC Solutions</li>
                        <li>Preventive Maintenance</li>
                    </ul>
                    <button class="service-more" type="button" aria-expanded="false">View details</button>
                </article>

                <article class="service-card">
                    <span class="service-tag">Power Systems</span>
                    <div class="service-icon">&#9889;</div>
                    <h3>Electrical Engineering</h3>
                    <p class="service-summary">Power distribution, analysis, panels, and electrical reliability work.</p>
                    <ul class="service-list">
                        <li>Electrical Power System Analysis</li>
                        <li>Voltage Drop Calculations</li>
                        <li>Load Distribution Design</li>
                        <li>Arc Flash Studies</li>
                        <li>Transformer Installation</li>
                        <li>Capacitor Banks & Panels</li>
                    </ul>
                    <button class="service-more" type="button" aria-expanded="false">View details</button>
                </article>

                <article class="service-card">
                    <span class="service-tag">Automation</span>
                    <div class="service-icon">&#129302;</div>
                    <h3>Electronics & Automation</h3>
                    <p class="service-summary">Automation, security, cabling, and machine integration support.</p>
                    <ul class="service-list">
                        <li>Factory Automation Systems</li>
                        <li>Building Management Systems</li>
                        <li>CCTV & Security Solutions</li>
                        <li>Structured Cabling</li>
                        <li>Fire Detection & Alarms</li>
                        <li>Production Machine Integration</li>
                    </ul>
                    <button class="service-more" type="button" aria-expanded="false">View details</button>
                </article>

                <article class="service-card">
                    <span class="service-tag">Controls</span>
                    <div class="service-icon">&#128187;</div>
                    <h3>PLC, SCADA & Controls</h3>
                    <p class="service-summary">Controls programming, panels, HMI, SCADA, and commissioning.</p>
                    <ul class="service-list">
                        <li>PLC Programming & Commissioning</li>
                        <li>HMI and SCADA Development</li>
                        <li>Control Panel Design & Assembly</li>
                        <li>Motor Control Center Integration</li>
                        <li>Instrumentation Calibration Support</li>
                        <li>Remote Monitoring Dashboards</li>
                    </ul>
                    <button class="service-more" type="button" aria-expanded="false">View details</button>
                </article>

                <article class="service-card">
                    <span class="service-tag">Energy</span>
                    <div class="service-icon">&#9728;</div>
                    <h3>Energy & Solar Solutions</h3>
                    <p class="service-summary">Solar, energy audits, power quality, and backup optimization.</p>
                    <ul class="service-list">
                        <li>Solar PV System Design</li>
                        <li>On-Grid and Hybrid Installations</li>
                        <li>Energy Audits & Load Profiling</li>
                        <li>Power Quality Improvement</li>
                        <li>Preventive Solar Maintenance</li>
                        <li>Backup Power Optimization</li>
                    </ul>
                    <button class="service-more" type="button" aria-expanded="false">View details</button>
                </article>

                <article class="service-card service-card-highlight">
                    <span class="service-tag">Modernization</span>
                    <div class="service-icon">&#128295;</div>
                    <h3>Technical Support & Modernization</h3>
                    <p class="service-summary">Retrofits, troubleshooting, documentation, testing, and turnover.</p>
                    <ul class="service-list">
                        <li>Machine Retrofits and Upgrades</li>
                        <li>Troubleshooting of Critical Systems</li>
                        <li>Plant Expansion Technical Support</li>
                        <li>Documentation, Testing & Turnover</li>
                        <li>Preventive and Corrective Maintenance</li>
                        <li>End-to-End Project Execution</li>
                    </ul>
                    <button class="service-more" type="button" aria-expanded="false">View details</button>
                </article>
            </div>
        </div>
    </section>

    <!-- Specialized Projects Section -->
    <section id="projects" class="projects">
        <div class="container">
            <h2 class="section-title">Completed Projects & Capabilities</h2>
            <p class="projects-intro">
                Field-proven engineering work by Edge Automation, covering utility systems, process piping, HVAC, water treatment, and electrical control solutions.
            </p>
            <div class="projects-grid">
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/1b222ae1-9eda-40a8-ab37-a916e4967da3.jpg">
                        <img src="../../IMAGES/1b222ae1-9eda-40a8-ab37-a916e4967da3.jpg" alt="Industrial utility equipment and process piping installation" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">01</span>
                            <h4>Utility Systems Installation</h4>
                            <p>Air, water, and process utility setup for reliable plant operations.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/49f05bef-5847-4f8e-a281-605581193654.jpg">
                        <img src="../../IMAGES/49f05bef-5847-4f8e-a281-605581193654.jpg" alt="Industrial filtration and water treatment piping system" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">02</span>
                            <h4>Water Treatment Systems</h4>
                            <p>Filtration, tanks, valves, and piping works for process water support.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/58f07dbc-5a25-4798-86bb-315d23d8b2fc.jpg">
                        <img src="../../IMAGES/58f07dbc-5a25-4798-86bb-315d23d8b2fc.jpg" alt="Outdoor industrial skid equipment with control cabinet" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">03</span>
                            <h4>Skid & Equipment Integration</h4>
                            <p>Packaged systems, control cabinets, and plant-side equipment installation.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/8708f0f0-f58a-4913-9686-76237dba2620.jpg">
                        <img src="../../IMAGES/8708f0f0-f58a-4913-9686-76237dba2620.jpg" alt="Electrical control panel with industrial piping system" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">04</span>
                            <h4>Electrical Controls</h4>
                            <p>Control panel wiring, commissioning, and automation support.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/961699e2-5901-461b-aaef-8696c3f965b7.jpg">
                        <img src="../../IMAGES/961699e2-5901-461b-aaef-8696c3f965b7.jpg" alt="Industrial water treatment line with filtration tanks and piping" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">05</span>
                            <h4>Process Piping Works</h4>
                            <p>Clean routing, installation, and improvement of industrial piping lines.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/b6b1dc8e-df1d-40e8-b5cc-aa73962d9908.jpg">
                        <img src="../../IMAGES/b6b1dc8e-df1d-40e8-b5cc-aa73962d9908.jpg" alt="HVAC ducting installation inside an industrial facility" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">06</span>
                            <h4>HVAC & Ducting</h4>
                            <p>Ventilation, ducting, and environmental support systems for facilities.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/c797a630-029b-4486-a1e2-cde9ed4ab8c6.jpg">
                        <img src="../../IMAGES/c797a630-029b-4486-a1e2-cde9ed4ab8c6.jpg" alt="Industrial water treatment equipment and piping assembly" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">07</span>
                            <h4>System Modernization</h4>
                            <p>Equipment upgrades and integration work for existing production utilities.</p>
                        </div>
                    </a>
                </article>
                <article class="project-item">
                    <a class="project-link" href="../../IMAGES/cc6bd15e-e5fe-4f90-b70d-c41b257f2853.jpg">
                        <img src="../../IMAGES/cc6bd15e-e5fe-4f90-b70d-c41b257f2853.jpg" alt="Industrial control panel with switches and meters" loading="lazy" decoding="async">
                        <div class="project-content">
                            <span class="project-number">08</span>
                            <h4>Machine Controls</h4>
                            <p>Panel controls, instrumentation, and operator-ready machine interfaces.</p>
                        </div>
                    </a>
                </article>
            </div>
        </div>
    </section>

    <!-- Technology Partners -->
    <section class="partners" aria-labelledby="partnersTitle">
        <div class="container">
            <h2 class="section-title" id="partnersTitle">Technology Partners</h2>
            <p class="partners-intro">
                We work with trusted industrial brands and automation technologies for practical, plant-ready solutions.
            </p>
            <figure class="partners-visual">
                <a class="partners-link image-lightbox-link" href="../../IMAGES/Edge Partner.png" data-lightbox-title="Technology Partners" aria-label="View technology partners image">
                    <img src="../../IMAGES/Edge Partner.png" alt="Edge Automation technology partners including Mitsubishi Electric, Epson, Keyence, and Buhler" loading="lazy" decoding="async">
                </a>
            </figure>
        </div>
    </section>

    <!-- Why Choose Us -->
    <section class="why-choose">
        <div class="container">
            <h2 class="section-title">Why Choose Us</h2>
            <p class="why-intro">
                Practical engineering support for teams that need reliable uptime, clean execution, and clear project turnover.
            </p>
            <div class="features-grid">
                <article class="feature">
                    <div class="feature-icon">&#127942;</div>
                    <h4>Field Experience</h4>
                    <p>Hands-on work across plant utilities, controls, and facility systems.</p>
                </article>
                <article class="feature">
                    <div class="feature-icon">&#9989;</div>
                    <h4>Reliable Execution</h4>
                    <p>Planned work, stable operation, and cleaner handover documentation.</p>
                </article>
                <article class="feature">
                    <div class="feature-icon">&#128200;</div>
                    <h4>Scalable Solutions</h4>
                    <p>Support that can grow with upgrades, expansions, and future plant needs.</p>
                </article>
                <article class="feature">
                    <div class="feature-icon">&#128295;</div>
                    <h4>End-to-End Support</h4>
                    <p>From troubleshooting and installation to testing and documentation.</p>
                </article>
                <article class="feature">
                    <div class="feature-icon">&#128737;</div>
                    <h4>Preventive Maintenance</h4>
                    <p>Support that helps reduce downtime and recurring equipment issues.</p>
                </article>
                <article class="feature">
                    <div class="feature-icon">&#128161;</div>
                    <h4>Innovation</h4>
                    <p>Practical technology improvements matched to real operational problems.</p>
                </article>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="contact">
        <div class="container">
            <h2 class="section-title">Client & Project Inquiries</h2>
            <div class="contact-content">
                <div class="contact-card contact-card-main">
                    <h3>Talk to Edge Automation</h3>
                    <p>
                        Share your plant, facility, or equipment requirement and our team will guide you through the next practical step.
                    </p>
                    <div class="contact-actions">
                        <button class="contact-action contact-action-primary" id="consultBtnSecondary" type="button">Request Consultation</button>
                    </div>
                </div>

                <div class="contact-card contact-card-details">
                    <h3>Contact Information</h3>
                    <dl class="contact-list">
                        <div>
                            <dt>Company</dt>
                            <dd>Edge Automation Technology Services, Co.</dd>
                        </div>
                        <div>
                            <dt>Location</dt>
                            <dd>Blk 4 Lot 16 Camella Dos Rios, Brgy. Pittland, Cabuyao, Laguna</dd>
                        </div>
                        <div>
                            <dt>Phone</dt>
                            <dd><a href="tel:+639178789571">0917 878 9571</a></dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd><a href="mailto:ejimenez.edge@gmail.com">ejimenez.edge@gmail.com</a></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </section>
    </main>

    <div class="mobile-contact-bar" aria-label="Quick contact actions">
        <a href="tel:+639178789571">Call</a>
        <a href="https://invite.viber.com/?number=639178789571" target="_blank" rel="noopener noreferrer">Viber</a>
        <a href="https://www.facebook.com/edgeautomationtsc" target="_blank" rel="noopener noreferrer">Facebook</a>
        <button id="consultBtnMobile" type="button">More</button>
    </div>
    <!-- Footer -->
    <footer class="footer">
        <div class="container footer-inner">
            <div class="footer-brand">
                <strong>Edge Automation Technology Services, Co.</strong>
                <p>Project asset inventory, quotation, reporting, and field operations support for industrial engineering work.</p>
            </div>
            <div class="footer-column">
                <h3>Navigation</h3>
                <nav class="footer-links" aria-label="Footer navigation">
                    <a href="#home">Home</a>
                    <a href="#about">About</a>
                    <a href="#services">Services</a>
                    <a href="#projects">Projects</a>
                    <a href="#contact">Contact</a>
                    <a href="login.php">Login</a>
                </nav>
            </div>
            <div class="footer-column">
                <h3>Contact</h3>
                <div class="footer-links">
                    <a href="tel:+639178789571">0917 878 9571</a>
                    <a href="mailto:ejimenez.edge@gmail.com">ejimenez.edge@gmail.com</a>
                    <span>Blk 4 Lot 16 Camella Dos Rios, Brgy. Pittland, Cabuyao, Laguna</span>
                </div>
            </div>
            <div class="footer-column">
                <h3>Social</h3>
                <div class="footer-social" aria-label="Social media links">
                    <a href="https://www.facebook.com/edgeautomationtsc" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                        <img src="../../IMAGES/fb.png" alt="" loading="lazy" decoding="async">
                    </a>
                    <a href="https://invite.viber.com/?number=639178789571" target="_blank" rel="noopener noreferrer" aria-label="Viber">
                        <img src="../../IMAGES/viber.jpg" alt="" loading="lazy" decoding="async">
                    </a>
                    <a href="mailto:ejimenez.edge@gmail.com?subject=Request%20Consultation" aria-label="Email">
                        <img src="../../IMAGES/gmail.jpg" alt="" loading="lazy" decoding="async">
                    </a>
                </div>
            </div>
            <p class="footer-copy">&copy; 2026 Group 11. All rights reserved.</p>
        </div>
    </footer>

<div id="projectLightbox" class="project-lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Project image viewer">
    <button class="project-lightbox-close" type="button" aria-label="Close project viewer">&times;</button>
    <button class="project-lightbox-nav project-lightbox-prev" type="button" aria-label="Previous project image">&#8249;</button>
    <figure class="project-lightbox-figure">
        <img id="projectLightboxImage" src="" alt="">
        <figcaption id="projectLightboxCaption"></figcaption>
    </figure>
    <button class="project-lightbox-nav project-lightbox-next" type="button" aria-label="Next project image">&#8250;</button>
</div>

<div id="consultModal" class="consult-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="consultModalTitle">
    <div class="consult-modal-content">
        <h3 id="consultModalTitle">How would you like to reach us?</h3>
        <p>Choose the channel that fits your request.</p>

        <div class="consult-buttons">
            <a href="tel:+639178789571" class="consult-option consult-option-fast" aria-label="Call Edge Automation">
                <strong>Fastest</strong>
                <span>Call 0917 878 9571</span>
            </a>

<a href="https://www.facebook.com/edgeautomationtsc" target="_blank" rel="noopener noreferrer" class="consult-option" aria-label="Facebook">                <img src="../../IMAGES/fb.png" alt="Facebook Messenger" loading="lazy" decoding="async">
                <strong>Facebook</strong>
                <span>Message the page</span>
            </a>

<a href="https://invite.viber.com/?number=639178789571" target="_blank" rel="noopener noreferrer" class="consult-option" aria-label="Viber">                <img src="../../IMAGES/viber.jpg" alt="Viber" loading="lazy" decoding="async">
                <strong>Viber</strong>
                <span>Start a chat</span>
            </a>

<a href="https://mail.google.com/mail/?view=cm&fs=1&to=ejimenez.edge@gmail.com&su=Request%20Consultation&body=Hello%20Edge%20Automation,%20I%20would%20like%20to%20request%20a%20consultation." target="_blank" class="consult-option">
    <img src="../../IMAGES/gmail.jpg" alt="Email" loading="lazy" decoding="async">
    <strong>Email</strong>
    <span>Send project details</span>
</a>

            <button id="openInquiryModal" class="consult-option consult-option-button" type="button">
                <img src="../../IMAGES/edge.jpg" alt="" loading="lazy" decoding="async">
                <strong>Inquiry</strong>
                <span>Fill out quotation request</span>
            </button>
        </div>

        <button id="closeConsult" class="consult-close" type="button">Close</button>
    </div>
</div>

<div id="inquiryModal" class="consult-modal inquiry-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="inquiryModalTitle">
    <div class="consult-modal-content inquiry-modal-content">
        <button id="closeInquiryModalX" class="modal-x-button" type="button" aria-label="Close inquiry form">&times;</button>
        <div class="inquiry-modal-head">
            <span class="section-kicker">Quotation Request</span>
            <h3 id="inquiryModalTitle">Inquiry and Quotation Request</h3>
            <p>Send your site details so Edge Automation can review your request.</p>
        </div>

        <form class="inquiry-form js-inquiry-form" action="submit_inquiry.php" method="POST" novalidate>
            <?php if (isset($_GET['inquiry'])): ?>
                <?php
                    $inquiryStatus = (string)$_GET['inquiry'];
                    $isInquirySuccess = $inquiryStatus === 'success';
                    $inquiryMessage = $isInquirySuccess
                        ? 'Your inquiry was sent. We will contact you soon.'
                        : ($inquiryStatus === 'invalid'
                            ? 'Please check the form and try again.'
                            : ($inquiryStatus === 'email_error'
                                ? 'We could not send the verification code. Please try again later.'
                                : ($inquiryStatus === 'expired'
                                    ? 'The verification code expired. Please submit the inquiry again.'
                                    : 'Sorry, the inquiry service had a problem. Please try again later.')));
                ?>
                <div class="inquiry-alert <?php echo $isInquirySuccess ? 'inquiry-alert-success' : 'inquiry-alert-error'; ?>">
                    <?php echo htmlspecialchars($inquiryMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="inquiry-grid">
                <label>
                    <span>Full Name <b class="required-mark">*</b></span>
                    <input type="text" name="client_name" data-label="Full Name" required autocomplete="name">
                    <small class="field-error"></small>
                </label>

                <label>
                    <span>Company / Organization Name</span>
                    <input type="text" name="company_name" autocomplete="organization" placeholder="Optional, leave blank if individual client">
                    <small class="field-error"></small>
                </label>

                <label>
                    <span>Email Address <b class="required-mark">*</b></span>
                    <input type="email" name="email" data-label="Email Address" required autocomplete="email">
                    <small class="field-error"></small>
                </label>

                <label>
                    <span>Contact Number <b class="required-mark">*</b></span>
                    <input type="tel" class="js-inquiry-contact" name="contact_no" data-label="Contact Number" required value="09" maxlength="11" inputmode="numeric" autocomplete="tel">
                    <small class="field-error"></small>
                </label>
            </div>

            <div class="inquiry-grid">
                <label>
                    <span>Province <b class="required-mark">*</b></span>
                    <span class="inquiry-combobox" data-combobox>
                        <input class="js-inquiry-province" name="province" data-label="Province" required placeholder="Search or select province" autocomplete="off" data-combobox-input>
                        <button class="inquiry-combobox-button" type="button" aria-label="Show province options" data-combobox-toggle></button>
                        <span class="inquiry-combobox-list" data-combobox-list></span>
                    </span>
                    <small class="field-error"></small>
                </label>

                <label>
                    <span>City / Municipality <b class="required-mark">*</b></span>
                    <span class="inquiry-combobox" data-combobox>
                        <input class="js-inquiry-city" name="city_municipality" data-label="City / Municipality" required placeholder="Select province first" autocomplete="off" disabled data-combobox-input>
                        <button class="inquiry-combobox-button" type="button" aria-label="Show city options" data-combobox-toggle></button>
                        <span class="inquiry-combobox-list" data-combobox-list></span>
                    </span>
                    <small class="field-error"></small>
                </label>
            </div>

            <label>
                <span>Barangay / Landmark Area <b class="required-mark">*</b></span>
                <span class="inquiry-combobox" data-combobox>
                    <input class="js-inquiry-barangay" type="text" name="barangay" data-label="Barangay / Landmark Area" required placeholder="Select city first" autocomplete="off" disabled data-combobox-input>
                    <button class="inquiry-combobox-button" type="button" aria-label="Show barangay suggestions" data-combobox-toggle></button>
                    <span class="inquiry-combobox-list" data-combobox-list></span>
                </span>
                <small class="field-error"></small>
            </label>

            <label>
                <span class="field-label-with-info">
                    Exact Site Address <b class="required-mark">*</b>
                    <span class="field-info-wrap">
                        <button class="field-info-button js-field-info-button" type="button" aria-label="Site address note">i</button>
                        <small class="field-tooltip js-field-tooltip">Service area is limited to Luzon only.</small>
                    </span>
                </span>
                <textarea name="site_address" data-label="Exact Site Address" rows="3" required data-help-tooltip-target placeholder="House/building no., street, gate, floor, or nearby details"></textarea>
                <small class="field-error"></small>
            </label>

            <div class="inquiry-grid">
                <label>
                    <span>Service Category <b class="required-mark">*</b></span>
                    <select name="service_category" data-label="Service Category" required>
                        <option value="">Select service</option>
                        <option>New Automation Installation</option>
                        <option>System Upgrade/Retrofitting</option>
                        <option>Preventive Maintenance</option>
                        <option>Emergency Troubleshooting</option>
                        <option>Other / Not sure yet</option>
                    </select>
                    <small class="field-error"></small>
                </label>

                <label>
                    <span class="field-label-with-info">
                        Preferred Inspection Date
                        <span class="field-info-wrap">
                            <button class="field-info-button js-date-info-button" type="button" aria-label="Inspection date note">i</button>
                            <small class="field-tooltip js-date-tooltip">This is only your preferred date. We will contact you to confirm the final schedule.</small>
                        </span>
                    </span>
                    <span class="date-input-wrap">
                        <input type="date" class="js-inspection-date" name="preferred_inspection_date" data-label="Target Inspection Date">
                        <button class="date-picker-button js-date-picker-button" type="button" aria-label="Open preferred inspection calendar">&#128197;</button>
                    </span>
                    <small class="field-error"></small>
                </label>
            </div>

            <label class="other-service-field is-hidden">
                <span>Other Service Details</span>
                <input type="text" name="other_service_details" data-label="Other Service Details" placeholder="Briefly describe the service needed">
                <small class="field-error"></small>
            </label>

            <label>
                <span class="field-label-with-info">
                    Project Description <b class="required-mark">*</b>
                    <span class="field-info-wrap">
                        <button class="field-info-button js-field-info-button" type="button" aria-label="Project description note">i</button>
                        <small class="field-tooltip js-field-tooltip">Use at least 10 to 20 characters. Example: equipment, issue, or project scope.</small>
                    </span>
                </span>
                <textarea name="description" data-label="Project Description" rows="4" required minlength="10" data-help-tooltip-target placeholder="Tell us the equipment, issue, site condition, or project scope."></textarea>
                <small class="field-error"></small>
            </label>

            <p class="inquiry-form-message js-inquiry-message" aria-live="polite"></p>
            <div class="inquiry-modal-actions">
                <button class="consult-close inquiry-clear-button js-clear-inquiry-draft" type="button">Clear form</button>
                <span class="inquiry-modal-primary-actions">
                    <button id="closeInquiryModal" class="consult-close" type="button">Close</button>
                    <button type="submit" class="btn btn-primary inquiry-submit">Submit Inquiry</button>
                </span>
            </div>
        </form>
    </div>
</div>
    <script>
        window.edgeServiceAreas = <?php echo json_encode($serviceAreas, JSON_UNESCAPED_SLASHES); ?>;
        window.edgeServiceBarangays = <?php echo json_encode($serviceBarangays, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="../js/loader.js" defer></script>
    <script src="../js/index.js" defer></script>

</body>

</html>
