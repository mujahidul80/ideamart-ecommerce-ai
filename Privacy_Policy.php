<?php
session_start();
require_once __DIR__ . '/db.php';

try {
    $setStmt = $pdo->query("SELECT storeName FROM SettingGeneral LIMIT 1");
    $settings = $setStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $settings = [];
}
$storeName = !empty($settings['storeName']) ? $settings['storeName'] : 'Idea Mart';

include 'Header.php';
?>

<div class="min-h-screen pt-28 md:pt-32 pb-28 md:pb-24 bg-slate-50 font-sans">
    <div class="max-w-4xl mx-auto px-6">
        <div class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tight">Privacy Policy</h1>
            <p class="text-slate-500 font-medium mt-3">Last updated: <?php echo date('F d, Y'); ?></p>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 p-8 md:p-12">
            <div class="prose prose-slate prose-blue max-w-none text-slate-600 leading-relaxed">
                <p>Welcome to <strong><?php echo htmlspecialchars($storeName); ?></strong>. We respect your privacy and are committed to protecting your personal data. This privacy policy will inform you about how we look after your personal data when you visit our website and tell you about your privacy rights and how the law protects you.</p>

                <h2 class="text-xl font-bold text-slate-800 mt-8 mb-4">1. Information We Collect</h2>
                <p>We may collect, use, store and transfer different kinds of personal data about you which we have grouped together as follows:</p>
                <ul class="list-disc pl-6 mb-6 space-y-2">
                    <li><strong>Identity Data:</strong> includes first name, last name, username or similar identifier.</li>
                    <li><strong>Contact Data:</strong> includes billing address, delivery address, email address and telephone numbers.</li>
                    <li><strong>Financial Data:</strong> includes payment card details (processed securely by our payment partners).</li>
                    <li><strong>Transaction Data:</strong> includes details about payments to and from you and other details of products you have purchased from us.</li>
                    <li><strong>Technical Data:</strong> includes internet protocol (IP) address, your login data, browser type and version, time zone setting and location, and other technology on the devices you use to access this website.</li>
                </ul>

                <h2 class="text-xl font-bold text-slate-800 mt-8 mb-4">2. How We Use Your Information</h2>
                <p>We will only use your personal data when the law allows us to. Most commonly, we will use your personal data in the following circumstances:</p>
                <ul class="list-disc pl-6 mb-6 space-y-2">
                    <li>Where we need to perform the contract we are about to enter into or have entered into with you (e.g., to process and deliver your order).</li>
                    <li>Where it is necessary for our legitimate interests (or those of a third party) and your interests and fundamental rights do not override those interests.</li>
                    <li>Where we need to comply with a legal or regulatory obligation.</li>
                </ul>

                <h2 class="text-xl font-bold text-slate-800 mt-8 mb-4">3. Data Security</h2>
                <p>We have put in place appropriate security measures to prevent your personal data from being accidentally lost, used or accessed in an unauthorized way, altered or disclosed. In addition, we limit access to your personal data to those employees, agents, contractors and other third parties who have a business need to know.</p>

                <h2 class="text-xl font-bold text-slate-800 mt-8 mb-4">4. Data Retention</h2>
                <p>We will only retain your personal data for as long as necessary to fulfill the purposes we collected it for, including for the purposes of satisfying any legal, accounting, or reporting requirements.</p>

                <h2 class="text-xl font-bold text-slate-800 mt-8 mb-4">5. Your Legal Rights</h2>
                <p>Under certain circumstances, you have rights under data protection laws in relation to your personal data, including the right to request access, correction, erasure, restriction, transfer, to object to processing, to portability of data and (where the lawful ground of processing is consent) to withdraw consent.</p>

                <h2 class="text-xl font-bold text-slate-800 mt-8 mb-4">6. Contact Us</h2>
                <p>If you have any questions about this privacy policy or our privacy practices, please contact our support team.</p>
            </div>
        </div>
    </div>
</div>

<?php include 'Footer.php'; ?>