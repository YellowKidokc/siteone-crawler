<?php

/*
 * This file is part of the SiteOne Crawler.
 *
 * (c) Ján Regeš <jan.reges@siteone.cz>
 */

declare(strict_types=1);

use Crawler\Analysis\AccessibilityAnalyzer;
use Crawler\Result\VisitedUrl;
use Crawler\Crawler;
use Crawler\FoundUrl;
use PHPUnit\Framework\TestCase;

class AccessibilityAnalyzerTest extends TestCase
{
    private AccessibilityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new AccessibilityAnalyzer();
    }

    /**
     * Helper method to create a VisitedUrl object for testing
     */
    private function createVisitedUrl(string $url = 'https://example.com/test'): VisitedUrl
    {
        return new VisitedUrl(
            uqId: md5($url),
            sourceUqId: 'source123',
            sourceAttr: FoundUrl::SOURCE_INIT_URL,
            url: $url,
            statusCode: 200,
            requestTime: 0.1,
            size: 1024,
            contentType: Crawler::CONTENT_TYPE_ID_HTML,
            contentTypeHeader: 'text/html',
            contentEncoding: null,
            extras: null,
            isExternal: false,
            isAllowedForCrawling: true,
            cacheType: 0,
            cacheLifetime: null
        );
    }

    /**
     * Test that hidden input fields are NOT flagged for missing aria-labels
     * and visible inputs without aria-labels ARE still properly flagged as critical
     */
    public function testHiddenInputFixWorksCorrectly()
    {
        $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <title>Test Page</title>
        </head>
        <body>
            <form>
                <!-- Hidden input - should NOT be flagged for missing aria-label -->
                <input type="hidden" name="_csrf" value="somevalue">
                
                <!-- Visible input without aria-label - should be flagged as critical -->
                <input type="text" name="username">
                
                <!-- Visible input WITH aria-label - should NOT be flagged -->
                <input type="text" name="password" aria-label="Password">
                
                <!-- Additional test cases -->
                <input type="hidden" name="session_id" value="abc123">
                <input type="email" name="email">
                <input type="password" name="confirm_password" aria-labelledby="confirm-label">
                
                <!-- Test other form elements -->
                <select name="country">
                    <option>USA</option>
                </select>
                
                <textarea name="message" aria-label="Your message"></textarea>
                
                <textarea name="comments"></textarea>
            </form>
        </body>
        </html>';

        // Create a DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        // Create a VisitedUrl object
        $visitedUrl = $this->createVisitedUrl();

        // Analyze the URL
        $result = $this->analyzer->analyzeVisitedUrl($visitedUrl, $html, $dom, []);

        // Check that we got a result
        $this->assertNotNull($result);

        // Get all findings
        $critical = $result->getCritical();
        $warning = $result->getWarning();
        $ok = $result->getOk();
        
        // Get critical details for aria labels analysis
        $criticalDetails = $result->getCriticalDetails();
        $ariaLabelCriticalDetails = $criticalDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_ARIA_LABELS] ?? [];

        // Verify that we have critical findings for missing aria-labels
        $ariaLabelsFound = false;
        foreach ($critical as $criticalMessage) {
            if (strpos($criticalMessage, "form element(s) without defined 'aria-label' or 'aria-labelledby'") !== false) {
                $ariaLabelsFound = true;
                // Should be 4 critical issues: username input, email input, select, and comments textarea
                $this->assertStringContainsString("4 form element(s)", $criticalMessage);
                break;
            }
        }
        $this->assertTrue($ariaLabelsFound, 'Should find critical aria-label issues');
        
        // Verify the specific elements flagged as critical
        $this->assertCount(4, $ariaLabelCriticalDetails, 'Should have exactly 4 critical aria-label issues');
        
        // Check that hidden inputs are NOT in the critical details
        foreach ($ariaLabelCriticalDetails as $detail) {
            $this->assertStringNotContainsString('type="hidden"', $detail, 'Hidden inputs should not be flagged for missing aria-labels');
            $this->assertStringNotContainsString('name="_csrf"', $detail, 'CSRF hidden input should not be flagged');
            $this->assertStringNotContainsString('name="session_id"', $detail, 'Session ID hidden input should not be flagged');
        }
        
        // Check that visible inputs without aria-labels ARE in the critical details
        $foundUsername = false;
        $foundEmail = false;
        $foundSelect = false;
        $foundComments = false;
        
        foreach ($ariaLabelCriticalDetails as $detail) {
            if (strpos($detail, 'name="username"') !== false) $foundUsername = true;
            if (strpos($detail, 'name="email"') !== false) $foundEmail = true;
            if (strpos($detail, 'name="country"') !== false) $foundSelect = true;
            if (strpos($detail, 'name="comments"') !== false) $foundComments = true;
        }
        
        $this->assertTrue($foundUsername, 'Username input without aria-label should be flagged as critical');
        $this->assertTrue($foundEmail, 'Email input without aria-label should be flagged as critical');
        $this->assertTrue($foundSelect, 'Select without aria-label should be flagged as critical');
        $this->assertTrue($foundComments, 'Comments textarea without aria-label should be flagged as critical');
        
        // Check that inputs WITH aria-labels are NOT in critical details
        foreach ($ariaLabelCriticalDetails as $detail) {
            $this->assertStringNotContainsString('name="password"', $detail, 'Password input with aria-label should not be flagged');
            $this->assertStringNotContainsString('name="confirm_password"', $detail, 'Confirm password input with aria-labelledby should not be flagged');
            $this->assertStringNotContainsString('name="message"', $detail, 'Message textarea with aria-label should not be flagged');
        }
    }

    /**
     * Test that hidden inputs are also not flagged for missing labels
     */
    public function testHiddenInputsNotFlaggedForMissingLabels()
    {
        $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <title>Test Page</title>
        </head>
        <body>
            <form>
                <!-- Hidden inputs - should NOT be flagged for missing labels -->
                <input type="hidden" name="_token" value="abc123">
                <input type="hidden" name="csrf_token" value="xyz789">
                
                <!-- Visible input without label - should be flagged -->
                <input type="text" name="username" id="username">
                
                <!-- Visible input WITH label - should NOT be flagged -->
                <label for="email">Email:</label>
                <input type="text" name="email" id="email">
            </form>
        </body>
        </html>';

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $visitedUrl = $this->createVisitedUrl();

        $result = $this->analyzer->analyzeVisitedUrl($visitedUrl, $html, $dom, []);

        $this->assertNotNull($result);
        
        $warning = $result->getWarning();
        $warningDetails = $result->getWarningDetails();
        $formLabelWarningDetails = $warningDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_FORM_LABELS] ?? [];

        // Should have exactly 1 warning for the username input without label
        $this->assertCount(1, $formLabelWarningDetails, 'Should have exactly 1 form label warning');
        
        // Check that hidden inputs are NOT in the warning details
        foreach ($formLabelWarningDetails as $detail) {
            $this->assertStringNotContainsString('type="hidden"', $detail, 'Hidden inputs should not be flagged for missing labels');
            $this->assertStringNotContainsString('name="_token"', $detail, 'Token hidden input should not be flagged');
            $this->assertStringNotContainsString('name="csrf_token"', $detail, 'CSRF token hidden input should not be flagged');
        }
        
        // Check that the username input IS flagged
        $foundUsername = false;
        foreach ($formLabelWarningDetails as $detail) {
            if (strpos($detail, 'name="username"') !== false) {
                $foundUsername = true;
                break;
            }
        }
        $this->assertTrue($foundUsername, 'Username input without label should be flagged as warning');
    }

    /**
     * Test edge case with only hidden inputs (should not generate any warnings/criticals)
     */
    public function testOnlyHiddenInputsGenerateNoIssues()
    {
        $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <title>Test Page</title>
        </head>
        <body>
            <form>
                <input type="hidden" name="_csrf" value="token1">
                <input type="hidden" name="session_id" value="session123">
                <input type="hidden" name="user_id" value="456">
            </form>
        </body>
        </html>';

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $visitedUrl = $this->createVisitedUrl();

        $result = $this->analyzer->analyzeVisitedUrl($visitedUrl, $html, $dom, []);

        $this->assertNotNull($result);
        
        $critical = $result->getCritical();
        $warning = $result->getWarning();
        $criticalDetails = $result->getCriticalDetails();
        $warningDetails = $result->getWarningDetails();

        // Should not have any form-related critical or warning issues
        $ariaLabelCriticals = $criticalDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_ARIA_LABELS] ?? [];
        $formLabelWarnings = $warningDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_FORM_LABELS] ?? [];
        
        $this->assertEmpty($ariaLabelCriticals, 'Should not have aria-label critical issues for hidden inputs only');
        $this->assertEmpty($formLabelWarnings, 'Should not have form label warnings for hidden inputs only');
    }

    /**
     * Test mixed scenario ensuring existing functionality is not broken
     */
    public function testMixedScenarioMaintainsExistingFunctionality()
    {
        $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <title>Test Page</title>
        </head>
        <body>
            <!-- Test image alt attributes -->
            <img src="good.jpg" alt="Good image">
            <img src="bad.jpg">
            
            <form>
                <!-- Hidden inputs -->
                <input type="hidden" name="_csrf" value="token">
                
                <!-- Regular inputs -->
                <label for="name">Name:</label>
                <input type="text" name="name" id="name" aria-label="Your name">
                
                <input type="email" name="email" id="email"> <!-- No label, no aria-label -->
                
                <!-- Buttons -->
                <button type="submit">Submit</button> <!-- No aria-label -->
                <button type="button" aria-label="Close">×</button>
            </form>
            
            <!-- Test roles -->
            <nav role="navigation">Navigation</nav>
            <main>Main content</main> <!-- No role -->
        </body>
        </html>';

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $visitedUrl = $this->createVisitedUrl();

        $result = $this->analyzer->analyzeVisitedUrl($visitedUrl, $html, $dom, []);

        $this->assertNotNull($result);
        
        $critical = $result->getCritical();
        $warning = $result->getWarning();
        $ok = $result->getOk();
        
        // Should have some warnings and criticals, but hidden input should not contribute to them
        $this->assertNotEmpty($warning, 'Should have some warnings');
        $this->assertNotEmpty($critical, 'Should have some critical issues');
        $this->assertNotEmpty($ok, 'Should have some OK results');
        
        // Check specific issues
        $criticalDetails = $result->getCriticalDetails();
        $warningDetails = $result->getWarningDetails();
        
        $ariaLabelCriticals = $criticalDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_ARIA_LABELS] ?? [];
        $formLabelWarnings = $warningDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_FORM_LABELS] ?? [];
        $imageAltWarnings = $warningDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_IMAGE_ALT_ATTRIBUTES] ?? [];
        $roleWarnings = $warningDetails[AccessibilityAnalyzer::ANALYSIS_MISSING_ROLES] ?? [];
        
        // Verify hidden input is not flagged
        foreach (array_merge($ariaLabelCriticals, $formLabelWarnings) as $detail) {
            $this->assertStringNotContainsString('name="_csrf"', $detail, 'Hidden CSRF input should not be flagged');
        }
        
        // Verify other functionality still works
        $this->assertNotEmpty($imageAltWarnings, 'Should flag image without alt');
        $this->assertNotEmpty($ariaLabelCriticals, 'Should flag email input without aria-label');
        $this->assertNotEmpty($formLabelWarnings, 'Should flag email input without label');
        $this->assertNotEmpty($roleWarnings, 'Should flag main element without role');
    }
}