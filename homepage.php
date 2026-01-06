<?php

require_once __DIR__ . '/db.php';

$upcomingEvents = [];
try {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience
        FROM event
        WHERE Date >= ?
        AND LOWER(TRIM(Target_Audience)) = 'all'
        ORDER BY Date ASC, Time ASC
        LIMIT 3
    ");
    $stmt->execute([$today]);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Homepage: Found " . count($upcomingEvents) . " events with Target_Audience = 'all'");
} catch (PDOException $e) {
    error_log("Error fetching upcoming events for homepage: " . $e->getMessage());
    $upcomingEvents = [];
}

$latestNews = [];
try {
    $stmt = $pdo->prepare("
        SELECT News_ID, Title, Content, Image_Path, Category, Published_At, Views
        FROM school_news
        WHERE Status = 'published'
        ORDER BY Published_At DESC
        LIMIT 3
    ");
    $stmt->execute();
    $latestNews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Homepage: Found " . count($latestNews) . " published news posts");
} catch (PDOException $e) {
    error_log("Error fetching news for homepage: " . $e->getMessage());
    $latestNews = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    require_once __DIR__ . '/functions/contact-form-handler.php';

    handleContactFormSubmission();

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HUDURY - Smart School Management Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <div class="top-nav">
        <div class="top-nav-container">
            <div class="top-nav-links">
                <a href="#"><i class="fas fa-briefcase"></i> Jobs & Scholarships</a>
                <a href="#"><i class="fas fa-map-marker-alt"></i> Aqaba Branch</a>
                <a href="#"><i class="fas fa-question-circle"></i> Help Desk</a>
            </div>
            <button class="lang-toggle" onclick="toggleLanguage()">English / العربية</button>
        </div>
    </div>

    <nav class="main-nav">
        <div class="nav-container">
            <a href="#" class="logo">HUDURY</a>
            <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="nav-menu">
                <a href="#home">Home</a>
                <a href="#about">About</a>
                <a href="#features">Features</a>
                <a href="#portals">Portals</a>
                <a href="#news">News</a>
                <a href="#contact">Contact</a>
                <a href="signin.php" class="btn-login">Sign In</a>
            </div>
            <div class="mobile-menu" id="mobileMenu">
                <a href="#home" onclick="toggleMobileMenu()">Home</a>
                <a href="#about" onclick="toggleMobileMenu()">About</a>
                <a href="#features" onclick="toggleMobileMenu()">Features</a>
                <a href="#portals" onclick="toggleMobileMenu()">Portals</a>
                <a href="#news" onclick="toggleMobileMenu()">News</a>
                <a href="#contact" onclick="toggleMobileMenu()">Contact</a>
                <a href="signin.php" class="btn-login" onclick="toggleMobileMenu();">Sign In</a>
            </div>
        </div>
    </nav>

    <section class="hero-section" id="home">
        <div class="floating-emoji">🎒</div>
        <div class="floating-emoji">📚</div>
        <div class="floating-emoji">✏️</div>
        <div class="floating-emoji">🎨</div>
        <div class="hero-content-wrapper">
            <div class="hero-content">
                <h1 data-en="Welcome to HUDURY" data-ar="مرحباً بك في حضوري">Welcome to HUDURY</h1>
                <p data-en="Smart School Management Platform - Making learning fun and exciting for everyone! 🎉" 
                   data-ar="منصة إدارة مدرسية ذكية - جعل التعلم ممتعاً ومثيراً للجميع! 🎉">
                    Smart School Management Platform - Making learning fun and exciting for everyone! 🎉
                </p>
                <div class="hero-buttons">
                    <a href="signin.php" class="btn-primary">
                        <span data-en="Get Started 🚀" data-ar="ابدأ الآن 🚀">Get Started 🚀</span>
                    </a>
                    <a href="#features" class="btn-primary">
                        <span data-en="Learn More 📖" data-ar="اعرف المزيد 📖">Learn More 📖</span>
                    </a>
                </div>
            </div>
            <div class="hero-images">
                <img src="https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=600&h=400&fit=crop" 
                     alt="Happy Students" 
                     class="hero-image hero-image-1"
                     onerror="this.style.display='none'">
                <img src="https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=500&h=400&fit=crop" 
                     alt="School Classroom" 
                     class="hero-image hero-image-2"
                     onerror="this.style.display='none'">
                <img src="https://images.unsplash.com/photo-1509062522246-3755977927d7?w=500&h=400&fit=crop" 
                     alt="Children Learning" 
                     class="hero-image hero-image-3"
                     onerror="this.style.display='none'">
            </div>
        </div>
    </section>

    <section class="stats-section">
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-number" data-target="50000">0</div>
                <div class="stat-label" data-en="Happy Users" data-ar="مستخدم سعيد">Happy Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏫</div>
                <div class="stat-number" data-target="500">0</div>
                <div class="stat-label" data-en="Amazing Schools" data-ar="مدارس رائعة">Amazing Schools</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎓</div>
                <div class="stat-number" data-target="100000">0</div>
                <div class="stat-label" data-en="Super Students" data-ar="طلاب ممتازون">Super Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-number" data-target="98">0</div>
                <div class="stat-label" data-en="Happy Rate" data-ar="معدل السعادة">Happy Rate</div>
            </div>
        </div>
    </section>

    <section class="quick-links-section" id="features">
        <div class="section-container">
            <h2 class="section-title" data-en="Quick Links" data-ar="روابط سريعة">Quick Links</h2>
            <div class="quick-links-grid">
                <div class="quick-link-card">
                    <div class="quick-link-icon">👨‍🎓</div>
                    <h3 data-en="Student Portal" data-ar="بوابة الطالب">Student Portal</h3>
                    <p data-en="Access grades, assignments, and academic information" data-ar="الوصول إلى الدرجات والواجبات والمعلومات الأكاديمية">Access grades, assignments, and academic information</p>
                </div>
                <div class="quick-link-card">
                    <div class="quick-link-icon">👩‍🏫</div>
                    <h3 data-en="Teacher Portal" data-ar="بوابة المعلم">Teacher Portal</h3>
                    <p data-en="Manage attendance, grades, and communicate with parents" data-ar="إدارة الحضور والدرجات والتواصل مع أولياء الأمور">Manage attendance, grades, and communicate with parents</p>
                </div>
                <div class="quick-link-card">
                    <div class="quick-link-icon">👨‍👩‍👧</div>
                    <h3 data-en="Parent Portal" data-ar="بوابة ولي الأمر">Parent Portal</h3>
                    <p data-en="Monitor your child's progress and stay connected" data-ar="راقب تقدم طفلك وابق على اتصال">Monitor your child's progress and stay connected</p>
                </div>

                </div>
               
            </div>
        </div>
    </section>

    <section class="events-section">
        <div class="section-container">
            <h2 class="section-title" data-en="Upcoming Events" data-ar="الأحداث القادمة">Upcoming Events</h2>
            <div class="events-grid">
                <?php if (empty($upcomingEvents)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                        <h3 data-en="No upcoming events" data-ar="لا توجد أحداث قادمة">No upcoming events</h3>
                        <p data-en="Check back later for exciting school events!" data-ar="تحقق لاحقاً للحصول على أحداث مدرسية مثيرة!">Check back later for exciting school events!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingEvents as $event): ?>
                        <?php
                        $eventDate = new DateTime($event['Date']);
                        $day = $eventDate->format('d');
                        $month = $eventDate->format('F');
                        $monthLabels = [
                            'January' => ['en' => 'January', 'ar' => 'يناير'],
                            'February' => ['en' => 'February', 'ar' => 'فبراير'],
                            'March' => ['en' => 'March', 'ar' => 'مارس'],
                            'April' => ['en' => 'April', 'ar' => 'أبريل'],
                            'May' => ['en' => 'May', 'ar' => 'مايو'],
                            'June' => ['en' => 'June', 'ar' => 'يونيو'],
                            'July' => ['en' => 'July', 'ar' => 'يوليو'],
                            'August' => ['en' => 'August', 'ar' => 'أغسطس'],
                            'September' => ['en' => 'September', 'ar' => 'سبتمبر'],
                            'October' => ['en' => 'October', 'ar' => 'أكتوبر'],
                            'November' => ['en' => 'November', 'ar' => 'نوفمبر'],
                            'December' => ['en' => 'December', 'ar' => 'ديسمبر']
                        ];
                        $monthLabel = $monthLabels[$month] ?? ['en' => $month, 'ar' => $month];
                        $locationStr = $event['Location'] ? htmlspecialchars($event['Location']) : 'Location TBA';
                        $timeStr = $event['Time'] ? date('g:i A', strtotime($event['Time'])) : '';
                        ?>
                        <div class="event-card">
                            <div class="event-date">
                                <div class="event-day"><?php echo $day; ?></div>
                                <div class="event-month" data-en="<?php echo $monthLabel['en']; ?>" data-ar="<?php echo $monthLabel['ar']; ?>"><?php echo $monthLabel['en']; ?></div>
                            </div>
                            <div class="event-content">
                                <h3 class="event-title"><?php echo htmlspecialchars($event['Title']); ?></h3>
                                <p class="event-location">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo $locationStr; ?>
                                    <?php if ($timeStr): ?>
                                        <br><i class="fas fa-clock" style="margin-right: 0.3rem;"></i> <?php echo $timeStr; ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($event['Description']): ?>
                                    <p style="font-size: 0.9rem; color: #666; margin: 0.5rem 0;">
                                        <?php echo htmlspecialchars(substr($event['Description'], 0, 100)) . (strlen($event['Description']) > 100 ? '...' : ''); ?>
                                    </p>
                                <?php endif; ?>
                                <a href="#contact" class="event-link" data-en="Learn More →" data-ar="اعرف المزيد →">Learn More →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="news-section" id="news">
        <div class="section-container">
            <h2 class="section-title" data-en="Latest News" data-ar="آخر الأخبار">Latest News</h2>
            <div class="news-grid">
                <?php if (empty($latestNews)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📰</div>
                        <h3 data-en="No news available" data-ar="لا توجد أخبار متاحة">No news available</h3>
                        <p data-en="Check back later for the latest school news!" data-ar="تحقق لاحقاً للحصول على آخر أخبار المدرسة!">Check back later for the latest school news!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($latestNews as $news): ?>
                        <?php
                        $publishedDate = new DateTime($news['Published_At']);
                        $formattedDate = $publishedDate->format('d/m/Y');
                        $categoryIcons = [
                            'announcement' => '📢',
                            'event' => '🎉',
                            'achievement' => '🏆',
                            'general' => '📰'
                        ];
                        $icon = $categoryIcons[$news['Category']] ?? '📰';
                        $imageUrl = $news['Image_Path'] ?: 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=400&h=250&fit=crop';
                        ?>
                        <div class="news-card">
                            <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($news['Title']); ?>" class="news-image" onerror="this.style.display='none'">
                            <div class="news-content">
                                <div class="news-date"><?php echo $icon; ?> <?php echo $formattedDate; ?></div>
                                <h3 class="news-title"><?php echo htmlspecialchars($news['Title']); ?></h3>
                                <p class="news-excerpt"><?php echo htmlspecialchars(substr($news['Content'], 0, 150)) . (strlen($news['Content']) > 150 ? '...' : ''); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="contact-section" id="contact">
        <div class="section-container">
            <h2 class="section-title" data-en="Get In Touch! 📞" data-ar="تواصل معنا! 📞">Get In Touch! 📞</h2>
            <p class="contact-subtitle" data-en="We'd love to hear from you! Send us a message and we'll respond as soon as possible. 🎉" 
               data-ar="نحب أن نسمع منك! أرسل لنا رسالة وسنرد في أقرب وقت ممكن. 🎉">
                We'd love to hear from you! Send us a message and we'll respond as soon as possible. 🎉
            </p>
            
            <div class="contact-wrapper">
                
                <div class="contact-info-section">
                    <div class="contact-info-card">
                        <div class="contact-icon-wrapper">
                            <div class="contact-icon">📞</div>
                            <div class="icon-ring"></div>
                        </div>
                        <h3 data-en="Phone" data-ar="الهاتف">Phone</h3>
                        <p>+962 6 5355000</p>
                        <a href="tel:+96265355000" class="contact-link" data-en="Call Now" data-ar="اتصل الآن">Call Now →</a>
                    </div>

                    <div class="contact-info-card">
                        <div class="contact-icon-wrapper">
                            <div class="contact-icon">✉️</div>
                            <div class="icon-ring"></div>
                        </div>
                        <h3 data-en="Email" data-ar="البريد الإلكتروني">Email</h3>
                        <p>info@hudury.edu.jo</p>
                        <a href="mailto:info@hudury.edu.jo" class="contact-link" data-en="Send Email" data-ar="أرسل بريد">Send Email →</a>
                    </div>

                    <div class="contact-info-card">
                        <div class="contact-icon-wrapper">
                            <div class="contact-icon">📍</div>
                            <div class="icon-ring"></div>
                        </div>
                        <h3 data-en="Address" data-ar="العنوان">Address</h3>
                        <p>Aljubeiha, Amman, Jordan</p>
                        <a href="#" class="contact-link" data-en="View Map" data-ar="عرض الخريطة">View Map →</a>
                    </div>

                    <div class="contact-info-card">
                        <div class="contact-icon-wrapper">
                            <div class="contact-icon">⏰</div>
                            <div class="icon-ring"></div>
                        </div>
                        <h3 data-en="Working Hours" data-ar="ساعات العمل">Working Hours</h3>
                        <p data-en="Mon - Fri: 8:00 AM - 4:00 PM" data-ar="الاثنين - الجمعة: 8:00 صباحاً - 4:00 مساءً">Mon - Fri: 8:00 AM - 4:00 PM</p>
                        <span class="contact-status" data-en="We're Open! 🎉" data-ar="نحن مفتوحون! 🎉">We're Open! 🎉</span>
                    </div>
                </div>

                <div class="contact-form-wrapper">
                    <form class="contact-form" id="contactForm" method="POST" action="">
                        <div class="form-group">
                            <label for="name">
                                <span class="label-icon">👤</span>
                                <span data-en="Your Name" data-ar="اسمك">Your Name</span>
                            </label>
                            <input type="text" id="name" name="name" required placeholder="Enter your name">
                            <div class="input-underline"></div>
                        </div>

                        <div class="form-group">
                            <label for="email">
                                <span class="label-icon">📧</span>
                                <span data-en="Your Email" data-ar="بريدك الإلكتروني">Your Email</span>
                            </label>
                            <input type="email" id="email" name="email" required placeholder="Enter your email">
                            <div class="input-underline"></div>
                        </div>

                        <div class="form-group">
                            <label for="phone">
                                <span class="label-icon">📱</span>
                                <span data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</span>
                            </label>
                            <input type="tel" id="phone" name="phone" placeholder="Enter your phone">
                            <div class="input-underline"></div>
                        </div>

                        <div class="form-group">
                            <label for="subject">
                                <span class="label-icon">📝</span>
                                <span data-en="Subject" data-ar="الموضوع">Subject</span>
                            </label>
                            <input type="text" id="subject" name="subject" required placeholder="What's this about?">
                            <div class="input-underline"></div>
                        </div>

                        <div class="form-group">
                            <label for="message">
                                <span class="label-icon">💬</span>
                                <span data-en="Your Message" data-ar="رسالتك">Your Message</span>
                            </label>
                            <textarea id="message" name="message" rows="5" required placeholder="Tell us what's on your mind..."></textarea>
                            <div class="input-underline"></div>
                        </div>

                        <button type="submit" class="submit-btn">
                            <span data-en="Send Message 🚀" data-ar="أرسل الرسالة 🚀">Send Message 🚀</span>
                            <div class="btn-shine"></div>
                        </button>
                    </form>
                </div>
            </div>

            <div class="social-contact-section">
                <h3 data-en="Follow Us On Social Media! 🌟" data-ar="تابعنا على وسائل التواصل الاجتماعي! 🌟">Follow Us On Social Media! 🌟</h3>
                <div class="social-contact-links">
                    <a href="#" class="social-contact-link facebook">
                        <div class="social-icon-bg"></div>
                        <i class="fab fa-facebook-f"></i>
                        <span>Facebook</span>
                    </a>
                    <a href="#" class="social-contact-link twitter">
                        <div class="social-icon-bg"></div>
                        <i class="fab fa-twitter"></i>
                        <span>Twitter</span>
                    </a>
                    <a href="#" class="social-contact-link instagram">
                        <div class="social-icon-bg"></div>
                        <i class="fab fa-instagram"></i>
                        <span>Instagram</span>
                    </a>
                    <a href="#" class="social-contact-link linkedin">
                        <div class="social-icon-bg"></div>
                        <i class="fab fa-linkedin-in"></i>
                        <span>LinkedIn</span>
                    </a>
                    <a href="#" class="social-contact-link youtube">
                        <div class="social-icon-bg"></div>
                        <i class="fab fa-youtube"></i>
                        <span>YouTube</span>
                    </a>
                    <a href="#" class="social-contact-link whatsapp">
                        <div class="social-icon-bg"></div>
                        <i class="fab fa-whatsapp"></i>
                        <span>WhatsApp</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="footer-container">
            <div class="footer-grid">
                <div class="footer-section">
                    <h3 data-en="About HUDURY" data-ar="حول حضوري">About HUDURY</h3>
                    <p data-en="A leading platform in educational management, transforming how schools connect, communicate, and grow. Making learning fun! 🎉" data-ar="منصة رائدة في الإدارة التعليمية، تحول كيفية اتصال المدارس والتواصل والنمو. جعل التعلم ممتعاً! 🎉">A leading platform in educational management, transforming how schools connect, communicate, and grow. Making learning fun! 🎉</p>
                    <div class="social-links">
                        <a href="#" class="social-link"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-linkedin"></i></a>
                    </div>
                </div>
                <div class="footer-section">
                    <h3 data-en="Quick Links" data-ar="روابط سريعة">Quick Links</h3>
                    <ul>
                        <li><a href="#features" data-en="Features" data-ar="المميزات">Features</a></li>
                        <li><a href="#portals" data-en="Portals" data-ar="البوابات">Portals</a></li>
                        <li><a href="#news" data-en="News" data-ar="الأخبار">News</a></li>
                        <li><a href="#" data-en="E-Learning" data-ar="التعلم الإلكتروني">E-Learning</a></li>
                        <li><a href="#" data-en="Library" data-ar="المكتبة">Library</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3 data-en="Information" data-ar="المعلومات">Information</h3>
                    <ul>
                        <li><a href="#" data-en="Admissions" data-ar="القبول">Admissions</a></li>
                        <li><a href="#" data-en="Academic Calendar" data-ar="التقويم الأكاديمي">Academic Calendar</a></li>
                        <li><a href="#" data-en="Regulations" data-ar="اللوائح">Regulations</a></li>
                        <li><a href="#" data-en="Policies" data-ar="السياسات">Policies</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3 data-en="Contact Info" data-ar="معلومات الاتصال">Contact Info</h3>
                    <p><i class="fas fa-phone"></i> +962 6 5355000</p>
                    <p><i class="fas fa-envelope"></i> info@hudury.edu.jo</p>
                    <p><i class="fas fa-map-marker-alt"></i> Aljubeiha, Amman, Jordan</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p data-en="© 2024 HUDURY Platform. All Rights Reserved. | University of Jordan - Computer Information Systems Department" data-ar="© 2024 منصة حضوري. جميع الحقوق محفوظة. | الجامعة الأردنية - قسم نظم المعلومات الحاسوبية">© 2024 HUDURY Platform. All Rights Reserved. | University of Jordan - Computer Information Systems Department</p>
            </div>
        </div>
    </footer>

    <div id="loginModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2.5rem; border-radius: 30px; max-width: 400px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: fadeIn 0.3s;">
            <span onclick="closeLoginModal()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;">Sign In 🎉</h2>
            <form onsubmit="handleLogin(event)">
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: #2c3e50; font-weight: 600;">Email / National ID</label>
                    <input type="text" required style="width: 100%; padding: 1rem; border: 3px solid #FFE5E5; border-radius: 15px; font-size: 1rem; transition: all 0.3s;" onfocus="this.style.borderColor='#FF6B9D'; this.style.transform='scale(1.02)'" onblur="this.style.borderColor='#FFE5E5'; this.style.transform='scale(1)'">
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: #2c3e50; font-weight: 600;">Password</label>
                    <input type="password" required style="width: 100%; padding: 1rem; border: 3px solid #FFE5E5; border-radius: 15px; font-size: 1rem; transition: all 0.3s;" onfocus="this.style.borderColor='#FF6B9D'; this.style.transform='scale(1.02)'" onblur="this.style.borderColor='#FFE5E5'; this.style.transform='scale(1)'">
                </div>
                <button type="submit" style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #FF6B9D, #6BCB77); color: white; border: none; border-radius: 25px; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: all 0.3s; box-shadow: 0 6px 20px rgba(255, 107, 157, 0.4);" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 25px rgba(255, 107, 157, 0.6)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 6px 20px rgba(255, 107, 157, 0.4)'">Sign In 🚀</button>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>