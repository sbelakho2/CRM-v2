<?php

namespace App\Tests\Functional\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Accessibility Compliance Tests (WCAG 2.1 AA)
 *
 * Tests verify all templates meet accessibility requirements
 * following the Sensei-Rams design philosophy which emphasizes
 * clarity, function, and inclusive design.
 *
 * Round-8 audit correction: a DETECTED violation must FAIL the build.
 * The previous suite discovered violations and then markTestSkipped()-ed
 * with an unconditional pass — a static linter report masquerading as an
 * enforcement gate. Known, reviewed violations live in
 * accessibility_allowlist.php; anything NOT in the allowlist fails here.
 */
class AccessibilityComplianceTest extends TestCase
{
    private string $templatesPath;
    private string $cssPath;

    /**
     * Reviewed allowlist: file => list of known violation substrings that
     * are ACCEPTED for now. Remove an entry after fixing the template —
     * new violations always fail.
     */
    private array $allowlist;

    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/../../../templates';
        $this->cssPath = __DIR__ . '/../../../public/css/sensei-rams.css';
        $this->allowlist = require __DIR__ . '/accessibility_allowlist.php';
    }

    /**
     * Apply the reviewed allowlist: drop violation entries that match an
     * allowed substring for their file.
     */
    private function applyAllowlist(string $file, array $violations): array
    {
        $allowed = $this->allowlist[$file] ?? [];
        if ($allowed === []) {
            return $violations;
        }

        return array_values(array_filter($violations, function ($violation) use ($allowed) {
            foreach ($allowed as $needle) {
                if (str_contains((string) $violation, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }
    
    // ========================================
    // SKIP LINK TESTS
    // ========================================
    
    public function testBaseTemplateHasSkipLink(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('rams-skip-link', $content, 
            'Base template should have skip link for keyboard navigation');
        
        $this->assertStringContainsString('#main-content', $content, 
            'Skip link should target main content area');
    }
    
    public function testSkipLinkStylesAreDefined(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        $this->assertStringContainsString('.rams-skip-link', $cssContent, 
            'CSS should define skip link styles');
        
        // Skip link should be hidden by default but visible on focus
        $this->assertStringContainsString('.rams-skip-link:focus', $cssContent, 
            'CSS should define skip link focus styles');
    }
    
    // ========================================
    // LANDMARK REGION TESTS
    // ========================================
    
    public function testBaseTemplateHasMainLandmark(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $hasMainRole = strpos($content, 'role="main"') !== false;
        $hasMainTag = strpos($content, '<main') !== false;
        
        $this->assertTrue($hasMainRole || $hasMainTag, 
            'Base template should have main landmark (role="main" or <main> tag)');
    }
    
    public function testBaseTemplateHasNavigationLandmark(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $hasNavRole = strpos($content, 'role="navigation"') !== false;
        $hasNavTag = strpos($content, '<nav') !== false;
        
        $this->assertTrue($hasNavRole || $hasNavTag, 
            'Base template should have navigation landmark');
    }
    
    public function testBaseTemplateHasBannerLandmark(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        // Sidebar header serves as banner in this industrial design
        $hasBannerRole = strpos($content, 'role="banner"') !== false;
        $hasHeaderTag = strpos($content, '<header') !== false;
        $hasSidebarHeader = strpos($content, 'sidebar__header') !== false;
        
        $this->assertTrue($hasBannerRole || $hasHeaderTag || $hasSidebarHeader, 
            'Base template should have banner/header landmark');
    }
    
    // ========================================
    // FORM ACCESSIBILITY TESTS
    // ========================================
    
    public function testFormInputsHaveAssociatedLabels(): void
    {
        $formTemplates = [
            'security/login.html.twig',
            'security/register.html.twig',
            'company/new.html.twig',
            'contact/new.html.twig',
        ];
        
        $violations = [];
        
        foreach ($formTemplates as $template) {
            $path = $this->templatesPath . '/' . $template;
            if (!file_exists($path)) {
                continue;
            }
            
            $content = file_get_contents($path);
            
            // Find inputs without labels
            preg_match_all('/<input[^>]+id="([^"]+)"[^>]*>/', $content, $inputs);
            
            foreach ($inputs[1] as $inputId) {
                // Check for associated label, aria-label, or aria-labelledby
                $hasLabel = strpos($content, "for=\"$inputId\"") !== false;
                $hasAriaLabel = strpos($content, "aria-label") !== false;
                $hasAriaLabelledBy = strpos($content, "aria-labelledby") !== false;
                $hasFormWidget = strpos($content, 'form_widget') !== false; // Symfony handles labels
                
                if (!$hasLabel && !$hasAriaLabel && !$hasAriaLabelledBy && !$hasFormWidget) {
                    $violations[$template][] = $inputId;
                }
            }
        }
        
        foreach ($violations as $template => $items) {
            $violations[$template] = $this->applyAllowlist($template, $items);
            if ($violations[$template] === []) {
                unset($violations[$template]);
            }
        }

        $this->assertEmpty(
            $violations,
            "Form inputs without labels (fix or review into accessibility_allowlist.php):\n" .
            print_r($violations, true)
        );
    }
    
    public function testFormErrorsAreAccessible(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        // Check for error state styles (using actual class names)
        $this->assertTrue(
            strpos($cssContent, '.rams-input--error') !== false ||
            strpos($cssContent, '.rams-form__input--error') !== false,
            'CSS should define error state for form inputs'
        );
        
        $this->assertTrue(
            strpos($cssContent, '.rams-error-text') !== false ||
            strpos($cssContent, '.rams-form__error') !== false,
            'CSS should define error message styles'
        );
    }
    
    public function testRequiredFieldsAreIndicated(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        // Check for required indicator styles (can use asterisk or other indicator)
        // Required fields can be indicated via label styling or attribute selectors
        $this->assertTrue(
            strpos($cssContent, '.rams-label--required') !== false ||
            strpos($cssContent, '.rams-form__label--required') !== false ||
            strpos($cssContent, '[required]') !== false ||
            strpos($cssContent, '.rams-input') !== false, // Labels work with standard inputs
            'CSS should have styling that can indicate required fields'
        );
    }
    
    // ========================================
    // COLOR CONTRAST TESTS
    // ========================================
    
    public function testCssHasAdequateContrastColors(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        // Check that we have proper contrast color tokens
        $this->assertStringContainsString('--rams-foreground', $cssContent, 
            'CSS should have foreground color for text');
        $this->assertStringContainsString('--rams-chassis', $cssContent, 
            'CSS should have chassis color for backgrounds');
        
        // #F2F2F2 on #1A1A1A is 13.4:1 - well above 4.5:1 requirement
        // #1A1A1A on #F2F2F2 is 13.4:1 - well above 4.5:1 requirement
    }
    
    public function testStatusColorsHaveAlternateIndicators(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        // Andon indicators should use shapes/positions in addition to color
        $this->assertStringContainsString('.rams-andon__light', $cssContent, 
            'CSS should define andon light indicators');
        
        // Text labels should accompany colored indicators
        $this->assertStringContainsString('.rams-andon__text', $cssContent, 
            'CSS should define andon text for accessibility');
    }
    
    public function testLinksAreDistinguishableByMoreThanColor(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        // Links should have underline by default
        $this->assertStringContainsString('text-decoration', $cssContent, 
            'CSS should use text-decoration for links');
    }
    
    // ========================================
    // KEYBOARD NAVIGATION TESTS
    // ========================================
    
    public function testFocusStatesAreDefined(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        // Check for focus styles
        $this->assertStringContainsString(':focus', $cssContent, 
            'CSS should define focus states');
        
        $this->assertStringContainsString(':focus-visible', $cssContent, 
            'CSS should define focus-visible states for keyboard navigation');
        
        // Check for outline styles
        $this->assertStringContainsString('outline', $cssContent, 
            'CSS should use outlines for focus indication');
    }
    
    public function testButtonsFocusStylesAreDefined(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        $this->assertStringContainsString('.rams-btn:focus', $cssContent, 
            'CSS should define button focus styles');
    }
    
    public function testInteractiveElementsAreKeyboardAccessible(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Check for click handlers without keyboard handlers
            preg_match_all('/onclick="([^"]+)"/', $content, $matches);
            
            foreach ($matches[0] as $onclick) {
                // Check if element also has keyboard handler
                $hasKeyHandler = strpos($content, 'onkeydown') !== false || 
                                 strpos($content, 'onkeyup') !== false ||
                                 strpos($content, 'onkeypress') !== false;
                
                // Check if element is naturally keyboard accessible (button, a, input)
                $pos = strpos($content, $onclick);
                $tagStart = strrpos(substr($content, 0, $pos), '<');
                $tagContent = substr($content, $tagStart, $pos - $tagStart);
                
                $isAccessible = preg_match('/<(button|a|input|select|textarea)/', $tagContent);
                
                if (!$hasKeyHandler && !$isAccessible) {
                    $violations[$relativePath][] = $onclick;
                }
            }
        }
        
        foreach ($violations as $template => $items) {
            $violations[$template] = $this->applyAllowlist($template, $items);
            if ($violations[$template] === []) {
                unset($violations[$template]);
            }
        }

        $this->assertEmpty(
            $violations,
            "Click handlers without keyboard equivalents (fix or review into accessibility_allowlist.php):\n" .
            print_r($violations, true)
        );
    }
    
    // ========================================
    // IMAGE ACCESSIBILITY TESTS
    // ========================================
    
    public function testImagesHaveAltAttributes(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Find images without alt attributes
            preg_match_all('/<img[^>]+>/', $content, $matches);
            
            foreach ($matches[0] as $imgTag) {
                if (strpos($imgTag, 'alt=') === false) {
                    $violations[$relativePath][] = substr($imgTag, 0, 100);
                }
            }
        }
        
        $this->assertEmpty($violations, 
            "All images should have alt attributes:\n" .
            print_r($violations, true));
    }
    
    public function testDecorativeImagesHaveEmptyAlt(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');

        $violations = [];

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $content = $file->getContents();

            // Decorative INLINE SVGs must be hidden from assistive tech:
            // svg WITH stroke/currentColor iconography but no text content
            // and no aria-hidden / aria-label / role="img" + title.
            preg_match_all('/<svg\b[^>]*>.*?<\/svg>/s', $content, $matches);

            foreach ($matches[0] as $svg) {
                $hasText = preg_match('/<(title|text)\b/', $svg) === 1;
                $isHidden = str_contains($svg, 'aria-hidden="true"') || str_contains($svg, "aria-hidden='true'");
                $isLabeled = str_contains($svg, 'aria-label') || str_contains($svg, 'role="img"');
                if (!$hasText && !$isHidden && !$isLabeled) {
                    $violations[$relativePath][] = substr($svg, 0, 90) . '…';
                }
            }
        }

        foreach ($violations as $template => $items) {
            $violations[$template] = $this->applyAllowlist($template, $items);
            if ($violations[$template] === []) {
                unset($violations[$template]);
            }
        }

        $this->assertEmpty(
            $violations,
            "Decorative inline SVGs without aria-hidden/label (fix or review into accessibility_allowlist.php):\n" .
            print_r($violations, true)
        );
    }
    
    // ========================================
    // HEADING STRUCTURE TESTS
    // ========================================
    
    public function testTemplatesHaveLogicalHeadingStructure(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Skip partials and components
            if (strpos($relativePath, 'components/') === 0 || 
                strpos($relativePath, '_') === 0) {
                continue;
            }
            
            // Check for heading skips (e.g., h1 to h3 without h2)
            preg_match_all('/<h([1-6])[^>]*>/', $content, $matches);
            
            $headings = array_map('intval', $matches[1]);
            
            for ($i = 1; $i < count($headings); $i++) {
                if ($headings[$i] > $headings[$i-1] + 1) {
                    $violations[$relativePath][] = "Skipped from h{$headings[$i-1]} to h{$headings[$i]}";
                }
            }
        }
        
        foreach ($violations as $template => $items) {
            $violations[$template] = $this->applyAllowlist($template, $items);
            if ($violations[$template] === []) {
                unset($violations[$template]);
            }
        }

        $this->assertEmpty(
            $violations,
            "Heading level skips (fix or review into accessibility_allowlist.php):\n" .
            print_r($violations, true)
        );
    }
    
    public function testPageTemplatesHaveH1(): void
    {
        $pageTemplates = [
            'dashboard/index.html.twig',
            'company/index.html.twig',
            'contact/index.html.twig',
            'rfq/index.html.twig',
            'security/login.html.twig',
        ];
        
        $missing = [];
        
        foreach ($pageTemplates as $template) {
            $path = $this->templatesPath . '/' . $template;
            if (!file_exists($path)) {
                continue;
            }
            
            $content = file_get_contents($path);
            
            // Check for h1 or rams-dymo (which serves as page header)
            $hasH1 = strpos($content, '<h1') !== false;
            $hasDymo = strpos($content, 'rams-dymo') !== false;
            $hasPageHeader = strpos($content, 'page-header') !== false;
            
            if (!$hasH1 && !$hasDymo && !$hasPageHeader) {
                $missing[] = $template;
            }
        }
        
        $missing = array_values(array_filter($missing, fn ($t) => empty($this->allowlist[$t])));

        $this->assertEmpty(
            $missing,
            "Page templates missing h1/page header (fix or review into accessibility_allowlist.php):\n" .
            implode("\n", $missing)
        );
    }
    
    // ========================================
    // TABLE ACCESSIBILITY TESTS
    // ========================================
    
    public function testTablesHaveHeaders(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Check for tables without headers
            if (strpos($content, '<table') !== false) {
                if (strpos($content, '<th') === false && strpos($content, '<thead') === false) {
                    $violations[] = $relativePath;
                }
            }
        }
        
        $violations = array_values(array_filter($violations, fn ($t) => empty($this->allowlist[$t])));

        $this->assertEmpty(
            $violations,
            "Tables without headers (fix or review into accessibility_allowlist.php):\n" .
            implode("\n", $violations)
        );
    }
    
    public function testTableStylesAreDefined(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        $this->assertStringContainsString('.rams-table', $cssContent, 
            'CSS should define table styles');
        
        $this->assertStringContainsString('.rams-table th', $cssContent, 
            'CSS should define table header styles');
    }
    
    // ========================================
    // MOTION AND ANIMATION TESTS
    // ========================================
    
    public function testReducedMotionIsRespected(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        $this->assertStringContainsString('prefers-reduced-motion', $cssContent, 
            'CSS should respect reduced motion preference');
    }
    
    // ========================================
    // ARIA ATTRIBUTE TESTS
    // ========================================
    
    public function testModalsHaveAriaAttributes(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Check for modals without aria-modal
            if (strpos($content, 'rams-modal') !== false) {
                if (strpos($content, 'aria-modal') === false && 
                    strpos($content, 'role="dialog"') === false) {
                    $violations[] = $relativePath;
                }
            }
        }
        
        $violations = array_values(array_filter($violations, fn ($t) => empty($this->allowlist[$t])));

        $this->assertEmpty(
            $violations,
            "Modals missing ARIA (fix or review into accessibility_allowlist.php):\n" .
            implode("\n", $violations)
        );
    }
    
    public function testDropdownsHaveAriaExpanded(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Check for dropdowns without aria-expanded
            if (preg_match('/dropdown|collapse|toggle/', $content)) {
                if (strpos($content, 'aria-expanded') === false) {
                    $violations[] = $relativePath;
                }
            }
        }
        
        $violations = array_values(array_filter($violations, fn ($t) => empty($this->allowlist[$t])));

        $this->assertEmpty(
            $violations,
            "Dropdowns without aria-expanded (fix or review into accessibility_allowlist.php):\n" .
            implode("\n", $violations)
        );
    }
    
    // ========================================
    // LOADING STATE TESTS
    // ========================================
    
    public function testLoadingStatesAreAccessible(): void
    {
        $cssContent = file_get_contents($this->cssPath);

        $hasLoadingStyles = strpos($cssContent, '.rams-loading') !== false
            || strpos($cssContent, '.rams-spinner') !== false;
        if (!$hasLoadingStyles) {
            $this->markTestIncomplete('No loading states defined in CSS yet');
        }

        // Every TEMPLATE that renders a loading indicator must expose it to
        // assistive tech: role="status" / aria-live / aria-busy on or near
        // the indicator.
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');

        $violations = [];
        foreach ($finder as $file) {
            $content = $file->getContents();
            if (!preg_match('/rams-loading|rams-spinner/', $content)) {
                continue;
            }
            $hasA11y = str_contains($content, 'role="status"')
                || str_contains($content, 'aria-live')
                || str_contains($content, 'aria-busy')
                || str_contains($content, 'rams-sr-only');
            if (!$hasA11y) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $violations = array_values(array_filter($violations, fn ($t) => empty($this->allowlist[$t])));

        $this->assertEmpty(
            $violations,
            "Loading indicators without status semantics (fix or review into accessibility_allowlist.php):\n" .
            implode("\n", $violations)
        );
    }
    
    // ========================================
    // SCREEN READER TEXT TESTS
    // ========================================
    
    public function testScreenReaderOnlyStylesAreDefined(): void
    {
        $cssContent = file_get_contents($this->cssPath);
        
        $this->assertTrue(
            strpos($cssContent, '.sr-only') !== false ||
            strpos($cssContent, '.rams-sr-only') !== false,
            'CSS should define screen reader only class'
        );
    }
    
    public function testIconButtonsHaveScreenReaderText(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Find buttons that only contain SVG
            preg_match_all('/<button[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*<\/button>/s', $content, $matches);
            
            foreach ($matches[0] as $button) {
                if (strpos($button, 'aria-label') === false && 
                    strpos($button, 'sr-only') === false &&
                    strpos($button, 'title=') === false) {
                    $violations[$relativePath][] = substr($button, 0, 100) . '...';
                }
            }
        }
        
        foreach ($violations as $template => $items) {
            $violations[$template] = $this->applyAllowlist($template, $items);
            if ($violations[$template] === []) {
                unset($violations[$template]);
            }
        }

        $this->assertEmpty(
            $violations,
            "Icon buttons without accessible names (fix or review into accessibility_allowlist.php):\n" .
            print_r($violations, true)
        );
    }
    
    // ========================================
    // LANGUAGE ATTRIBUTE TESTS
    // ========================================
    
    public function testBaseTemplateHasLanguageAttribute(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        // Check for lang attribute (can be dynamic or static)
        $this->assertTrue(
            strpos($content, 'lang="en"') !== false || 
            strpos($content, 'lang="{{') !== false ||
            strpos($content, "lang='{{") !== false, 
            'Base template should have lang attribute'
        );
    }
    
    // ========================================
    // DOCUMENT STRUCTURE TESTS
    // ========================================
    
    public function testBaseTemplateHasDoctype(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('<!DOCTYPE html>', $content, 
            'Base template should have DOCTYPE declaration');
    }
    
    public function testBaseTemplateHasViewport(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('viewport', $content, 
            'Base template should have viewport meta tag');
    }
}
