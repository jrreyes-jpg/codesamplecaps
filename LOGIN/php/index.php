<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edge Automation Technology Services, Co. - Professional engineering automation and technology solutions">
    <title>Edge Automation Technology Services, Co.</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/loader.css">
    <link rel="stylesheet" href="../css/index.css">
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">

</head>
<body>
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
            <div class="nav-logo">
                <img src="../../IMAGES/edge.jpg" alt="Edge Automation logo" class="logo-img">
                <span class="logo-text">EDGE AUTOMATION</span>
            </div>
            <ul class="nav-menu">
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
            <div class="hamburger">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-content">
            <h1 class="hero-title">Engineering Innovation at Scale</h1>
            <p class="hero-subtitle">Specialists in automation, electrical systems, and industrial solutions</p>
            <div class="cta-buttons">
<button class="btn btn-primary" id="consultBtn">Request Consultation</button>                <a href="#services" class="btn btn-secondary">View Services</a>
            </div>
        </div>
        <div class="hero-overlay"></div>
    </section>

    <!-- About Section -->
    <section id="about" class="about">
        <div class="container">
            <h2 class="section-title">About Us</h2>
            <div class="about-content">
                <p class="about-text">
                    Edge Automation Technology Services, Co. is a leading engineering firm specializing in mechanical engineering, electrical systems, and advanced automation solutions. With years of industry expertise, we deliver turnkey solutions for industrial clients seeking reliable, scalable, and innovative technology deployments.
                </p>
                <p class="about-text">
                    We combine deep technical knowledge with practical implementation experience to transform business challenges into competitive advantages through smart automation and systems integration.
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
                <div class="service-card">
                    <div class="service-icon">&#9881;</div>
                    <h3>Mechanical Engineering</h3>
                    <ul class="service-list">
                        <li>Utility Systems (Air, Water, Steam)</li>
                        <li>Fire Protection Systems</li>
                        <li>Diesel Generator Systems</li>
                        <li>Cleanroom Systems</li>
                        <li>HVAC Solutions</li>
                        <li>Preventive Maintenance</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon">&#9889;</div>
                    <h3>Electrical Engineering</h3>
                    <ul class="service-list">
                        <li>Electrical Power System Analysis</li>
                        <li>Voltage Drop Calculations</li>
                        <li>Load Distribution Design</li>
                        <li>Arc Flash Studies</li>
                        <li>Transformer Installation</li>
                        <li>Capacitor Banks & Panels</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon">&#129302;</div>
                    <h3>Electronics & Automation</h3>
                    <ul class="service-list">
                        <li>Factory Automation Systems</li>
                        <li>Building Management Systems</li>
                        <li>CCTV & Security Solutions</li>
                        <li>Structured Cabling</li>
                        <li>Fire Detection & Alarms</li>
                        <li>Production Machine Integration</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon">&#128187;</div>
                    <h3>PLC, SCADA & Controls</h3>
                    <ul class="service-list">
                        <li>PLC Programming & Commissioning</li>
                        <li>HMI and SCADA Development</li>
                        <li>Control Panel Design & Assembly</li>
                        <li>Motor Control Center Integration</li>
                        <li>Instrumentation Calibration Support</li>
                        <li>Remote Monitoring Dashboards</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon">&#9728;</div>
                    <h3>Energy & Solar Solutions</h3>
                    <ul class="service-list">
                        <li>Solar PV System Design</li>
                        <li>On-Grid and Hybrid Installations</li>
                        <li>Energy Audits & Load Profiling</li>
                        <li>Power Quality Improvement</li>
                        <li>Preventive Solar Maintenance</li>
                        <li>Backup Power Optimization</li>
                    </ul>
                </div>

                <div class="service-card service-card-highlight">
                    <div class="service-icon">&#128295;</div>
                    <h3>Technical Support & Modernization</h3>
                    <ul class="service-list">
                        <li>Machine Retrofits and Upgrades</li>
                        <li>Troubleshooting of Critical Systems</li>
                        <li>Plant Expansion Technical Support</li>
                        <li>Documentation, Testing & Turnover</li>
                        <li>Preventive and Corrective Maintenance</li>
                        <li>End-to-End Project Execution</li>
                    </ul>
                </div>
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
                    <img src="../../IMAGES/1b222ae1-9eda-40a8-ab37-a916e4967da3.jpg" alt="Industrial utility equipment and process piping installation">
                    <div class="project-content">
                        <span class="project-number">01</span>
                        <h4>Utility Systems Installation</h4>
                        <p>Air, water, and process utility setup for reliable plant operations.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/49f05bef-5847-4f8e-a281-605581193654.jpg" alt="Industrial filtration and water treatment piping system">
                    <div class="project-content">
                        <span class="project-number">02</span>
                        <h4>Water Treatment Systems</h4>
                        <p>Filtration, tanks, valves, and piping works for process water support.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/58f07dbc-5a25-4798-86bb-315d23d8b2fc.jpg" alt="Outdoor industrial skid equipment with control cabinet">
                    <div class="project-content">
                        <span class="project-number">03</span>
                        <h4>Skid & Equipment Integration</h4>
                        <p>Packaged systems, control cabinets, and plant-side equipment installation.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/8708f0f0-f58a-4913-9686-76237dba2620.jpg" alt="Electrical control panel with industrial piping system">
                    <div class="project-content">
                        <span class="project-number">04</span>
                        <h4>Electrical Controls</h4>
                        <p>Control panel wiring, commissioning, and automation support.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/961699e2-5901-461b-aaef-8696c3f965b7.jpg" alt="Industrial water treatment line with filtration tanks and piping">
                    <div class="project-content">
                        <span class="project-number">05</span>
                        <h4>Process Piping Works</h4>
                        <p>Clean routing, installation, and improvement of industrial piping lines.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/b6b1dc8e-df1d-40e8-b5cc-aa73962d9908.jpg" alt="HVAC ducting installation inside an industrial facility">
                    <div class="project-content">
                        <span class="project-number">06</span>
                        <h4>HVAC & Ducting</h4>
                        <p>Ventilation, ducting, and environmental support systems for facilities.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/c797a630-029b-4486-a1e2-cde9ed4ab8c6.jpg" alt="Industrial water treatment equipment and piping assembly">
                    <div class="project-content">
                        <span class="project-number">07</span>
                        <h4>System Modernization</h4>
                        <p>Equipment upgrades and integration work for existing production utilities.</p>
                    </div>
                </article>
                <article class="project-item">
                    <img src="../../IMAGES/cc6bd15e-e5fe-4f90-b70d-c41b257f2853.jpg" alt="Industrial control panel with switches and meters">
                    <div class="project-content">
                        <span class="project-number">08</span>
                        <h4>Machine Controls</h4>
                        <p>Panel controls, instrumentation, and operator-ready machine interfaces.</p>
                    </div>
                </article>
                <div class="projects-summary">
                    <h3>Solutions We Offer</h3>
                    <ul>
                        <li>Machine improvement and troubleshooting</li>
                        <li>Process optimization and equipment integration</li>
                        <li>Utility, HVAC, piping, and water treatment systems</li>
                        <li>Electrical controls, panel works, and automation support</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Choose Us -->
    <section class="why-choose">
        <div class="container">
            <h2 class="section-title">Why Choose Us</h2>
            <div class="features-grid">
                <div class="feature">
                    <div class="feature-icon">&#127942;</div>
                    <h4>Industry Expertise</h4>
                    <p>Years of proven experience in industrial automation</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">&#9989;</div>
                    <h4>Reliable Systems</h4>
                    <p>Engineered for stability and long-term performance</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">&#128200;</div>
                    <h4>Scalable Solutions</h4>
                    <p>Grow your operations with our flexible systems</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">&#128295;</div>
                    <h4>End-to-End Support</h4>
                    <p>From planning through implementation and beyond</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">&#128737;</div>
                    <h4>Preventive Care</h4>
                    <p>Minimize downtime with proactive maintenance</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">&#128161;</div>
                    <h4>Innovation</h4>
                    <p>Cutting-edge technology and best practices</p>
                </div>
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
                        <a href="tel:+639178789571" class="contact-action contact-action-primary">Call 0917 878 9571</a>
                        <button class="contact-action" id="consultBtnSecondary" type="button">Request Consultation</button>
                    </div>
                </div>

                <div class="contact-card contact-card-details">
                    <h3>Contact Information</h3>
                    <p>
                        <strong>Company:</strong> Edge Automation Technology Services, Co.<br>
                        <strong>Location:</strong> Blk 4 Lot 16 Camella Dos Rios, Brgy. Pittland, Cabuyao, Laguna<br>
                        <strong>Specialization:</strong> Industrial Automation & Engineering<br>
                        <strong>Email:</strong> <a href="mailto:ejimenez.edge@gmail.com">ejimenez.edge@gmail.com</a>
                    </p>

                    <div class="social-links" id="socialLinks" aria-label="Contact platforms">
                        <a href="https://www.facebook.com/edgeautomationtsc" target="_blank" rel="noopener noreferrer" class="social-icon" aria-label="Facebook Page">
                            <img src="https://cdn-icons-png.flaticon.com/512/733/733547.png" alt="" width="24">
                        </a>
                        <a href="https://invite.viber.com/?number=639178789571" target="_blank" rel="noopener noreferrer" class="social-icon" aria-label="Viber Chat">
                            <img src="https://cdn-icons-png.flaticon.com/512/3670/3670059.png" alt="" width="24">
                        </a>
                        <a href="mailto:ejimenez.edge@gmail.com?subject=Request%20Consultation" class="social-icon" aria-label="Email">
                            <img src="https://cdn-icons-png.flaticon.com/512/732/732200.png" alt="" width="24">
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="mobile-contact-bar" aria-label="Quick contact actions">
        <a href="tel:+639178789571">Call</a>
        <a href="https://invite.viber.com/?number=639178789571" target="_blank" rel="noopener noreferrer">Viber</a>
        <a href="https://www.facebook.com/edgeautomationtsc" target="_blank" rel="noopener noreferrer">FB</a>
        <button id="consultBtnMobile" type="button">Consult</button>
    </div>
    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 Edge Automation Technology Services, Co. All rights reserved.</p>
        </div>
    </footer>

<div id="consultModal" class="consult-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="consultModalTitle">
    <div class="consult-modal-content">
        <h3 id="consultModalTitle">Request Consultation</h3>
        <p>Choose your preferred platform:</p>

        <div class="consult-buttons">
<a href="https://www.facebook.com/edgeautomationtsc" target="_blank" rel="noopener noreferrer" class="consult-option" aria-label="Facebook">                <img src="https://cdn-icons-png.flaticon.com/512/733/733547.png" alt="Facebook Messenger">
                <span>Facebook Page</span>
            </a>

<a href="https://invite.viber.com/?number=639178789571" target="_blank" rel="noopener noreferrer" class="consult-option" aria-label="Viber">                <img src="https://cdn-icons-png.flaticon.com/512/3670/3670059.png" alt="Viber">
                <span>Viber Chat</span>
            </a>

<a href="https://mail.google.com/mail/?view=cm&fs=1&to=ejimenez.edge@gmail.com&su=Request%20Consultation&body=Hello%20Edge%20Automation,%20I%20would%20like%20to%20request%20a%20consultation." target="_blank" class="consult-option">
    <img src="https://cdn-icons-png.flaticon.com/512/732/732200.png" alt="Email">
    <span>Email</span>
</a>      </div>

        <button id="closeConsult" class="consult-close" type="button">Close</button>
    </div>
</div>
    <script src="../js/loader.js"></script>
    <script src="../js/index.js"></script>

</body>

</html>
