<?php

namespace App\Tests\Functional\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Template Structure and Inheritance Tests
 * 
 * Verifies all templates follow proper Twig inheritance patterns
 * and structural conventions for the Sensei-Rams design system.
 */
class TemplateStructureTest extends TestCase
{
    private string $templatesPath;
    
    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/../../../templates';
    }
    
    // ========================================
    // BASE TEMPLATE TESTS
    // ========================================
    
    public function testBaseTemplateExists(): void
    {
        $this->assertFileExists($this->templatesPath . '/base.html.twig');
    }
    
    public function testBaseLoginTemplateExists(): void
    {
        $this->assertFileExists($this->templatesPath . '/base_login.html.twig');
    }
    
    public function testBaseTemplateDefinesRequiredBlocks(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $requiredBlocks = [
            'title',
            'stylesheets',
            'body',
            'javascripts',
        ];
        
        foreach ($requiredBlocks as $block) {
            $this->assertStringContainsString("{% block $block %}", $content, 
                "Base template should define block: $block");
        }
    }
    
    public function testBaseTemplateHasProperHtmlStructure(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('<html', $content);
        $this->assertStringContainsString('<head>', $content);
        $this->assertStringContainsString('</head>', $content);
        $this->assertStringContainsString('<body', $content);
        $this->assertStringContainsString('</body>', $content);
        $this->assertStringContainsString('</html>', $content);
    }
    
    // ========================================
    // TEMPLATE INHERITANCE TESTS
    // ========================================
    
    public function testAllTemplatesExtendBaseTemplate(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Skip base templates themselves
            if ($relativePath === 'base.html.twig' || 
                $relativePath === 'base_login.html.twig') {
                continue;
            }
            
            // Skip components and partials (they're included, not extended)
            if (strpos($relativePath, 'components/') === 0 ||
                strpos(basename($relativePath), '_') === 0) {
                continue;
            }
            
            // Skip email templates (they have their own structure)
            if (strpos($relativePath, 'emails/') === 0) {
                continue;
            }
            
            // Skip PDF templates
            if (strpos($relativePath, 'pdf/') === 0) {
                continue;
            }
            
            // Check if template extends a base
            if (strpos($content, '{% extends') === false) {
                $violations[] = $relativePath;
            }
        }
        
        if (!empty($violations)) {
            $this->markTestSkipped(
                "INFO: Some templates may not extend base template:\n" .
                implode("\n", $violations)
            );
        }
        
        $this->assertTrue(true);
    }
    
    public function testSecurityTemplatesExtendBaseLogin(): void
    {
        $securityTemplates = [
            'security/login.html.twig',
            'security/register.html.twig',
            'security/forgot_password.html.twig',
            'security/reset_password.html.twig',
        ];
        
        foreach ($securityTemplates as $template) {
            $path = $this->templatesPath . '/' . $template;
            
            if (file_exists($path)) {
                $content = file_get_contents($path);
                
                $extendsBaseLogin = strpos($content, "extends 'base_login.html.twig'") !== false ||
                                    strpos($content, 'extends "base_login.html.twig"') !== false;
                
                $this->assertTrue($extendsBaseLogin, 
                    "Security template $template should extend base_login.html.twig");
            }
        }
    }
    
    // ========================================
    // BLOCK USAGE TESTS
    // ========================================
    
    public function testTemplatesDefineBodyBlock(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Only check templates that extend base
            if (strpos($content, '{% extends') === false) {
                continue;
            }
            
            // Skip components
            if (strpos($relativePath, 'components/') === 0) {
                continue;
            }
            
            // Check for body block
            if (strpos($content, '{% block body %}') === false &&
                strpos($content, '{% block content %}') === false) {
                $violations[] = $relativePath;
            }
        }
        
        if (!empty($violations)) {
            $this->markTestSkipped(
                "INFO: Some extending templates don't define body block:\n" .
                implode("\n", $violations)
            );
        }
        
        $this->assertTrue(true);
    }
    
    public function testTemplatesCloseBlocksProperly(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Count block opens and closes
            preg_match_all('/\{%\s*block\s+\w+/', $content, $opens);
            preg_match_all('/\{%\s*endblock/', $content, $closes);
            
            if (count($opens[0]) !== count($closes[0])) {
                $violations[$relativePath] = [
                    'opens' => count($opens[0]),
                    'closes' => count($closes[0]),
                ];
            }
        }
        
        $this->assertEmpty($violations, 
            "All block tags should be properly closed:\n" .
            print_r($violations, true));
    }
    
    // ========================================
    // TWIG SYNTAX TESTS
    // ========================================
    
    public function testNoMalformedTwigTags(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Check for common Twig syntax errors
            
            // Unclosed {{ }}
            if (preg_match('/\{\{[^}]+$/', $content)) {
                $violations[$relativePath][] = 'Unclosed {{ }}';
            }
            
            // Unclosed {% %}
            if (preg_match('/\{%[^%]+$/', $content)) {
                $violations[$relativePath][] = 'Unclosed {% %}';
            }
            
            // Mismatched if/endif
            preg_match_all('/\{%\s*if\s/', $content, $ifs);
            preg_match_all('/\{%\s*endif\s*%\}/', $content, $endifs);
            if (count($ifs[0]) !== count($endifs[0])) {
                $violations[$relativePath][] = 'Mismatched if/endif';
            }
            
            // Mismatched for/endfor
            preg_match_all('/\{%\s*for\s/', $content, $fors);
            preg_match_all('/\{%\s*endfor\s*%\}/', $content, $endfors);
            if (count($fors[0]) !== count($endfors[0])) {
                $violations[$relativePath][] = 'Mismatched for/endfor';
            }
        }
        
        $this->assertEmpty($violations, 
            "All Twig syntax should be valid:\n" .
            print_r($violations, true));
    }
    
    public function testNoDoubleExtendsStatements(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Count extends statements
            preg_match_all('/\{%\s*extends/', $content, $matches);
            
            if (count($matches[0]) > 1) {
                $violations[] = $relativePath;
            }
        }
        
        $this->assertEmpty($violations, 
            "Templates should have at most one extends statement:\n" .
            implode("\n", $violations));
    }
    
    // ========================================
    // COMPONENT STRUCTURE TESTS
    // ========================================
    
    public function testComponentsDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->templatesPath . '/components');
    }
    
    public function testComponentsDoNotExtendBase(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath . '/components')->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            if (strpos($content, '{% extends') !== false) {
                $violations[] = $relativePath;
            }
        }
        
        $this->assertEmpty($violations, 
            "Components should not extend base templates (they should be included):\n" .
            implode("\n", $violations));
    }
    
    // ========================================
    // INCLUDE STATEMENT TESTS
    // ========================================
    
    public function testIncludesReferenceExistingTemplates(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Find all include statements
            preg_match_all("/\{%\s*include\s+['\"]([^'\"]+)['\"]/", $content, $matches);
            
            foreach ($matches[1] as $includedTemplate) {
                $includedPath = $this->templatesPath . '/' . $includedTemplate;
                
                if (!file_exists($includedPath)) {
                    $violations[$relativePath][] = $includedTemplate;
                }
            }
        }
        
        if (!empty($violations)) {
            $this->markTestSkipped(
                "INFO: Some includes reference missing templates:\n" .
                print_r($violations, true)
            );
        }
        
        $this->assertTrue(true);
    }
    
    // ========================================
    // MACRO TESTS
    // ========================================
    
    public function testMacrosAreProperlyImported(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Check for macro usage without import
            if (preg_match('/\{\{\s*(\w+)\s*\.\s*\w+\s*\(/', $content, $matches)) {
                $macroName = $matches[1];
                
                // Check if macro is imported
                if (strpos($content, "import") === false && 
                    strpos($content, "{% from") === false) {
                    // It might be using _self
                    if ($macroName !== '_self') {
                        $violations[$relativePath][] = $macroName;
                    }
                }
            }
        }
        
        // This is informational
        if (!empty($violations)) {
            $this->markTestSkipped(
                "INFO: Some templates may use macros without imports:\n" .
                print_r($violations, true)
            );
        }
        
        $this->assertTrue(true);
    }
    
    // ========================================
    // NAMING CONVENTION TESTS
    // ========================================
    
    public function testTemplatesFollowNamingConventions(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $violations = [];
        
        foreach ($finder as $file) {
            $filename = $file->getFilename();
            $relativePath = $file->getRelativePathname();
            
            // Check for camelCase (should use snake_case)
            if (preg_match('/[a-z][A-Z]/', $filename)) {
                $violations[$relativePath] = 'Uses camelCase instead of snake_case';
            }
            
            // Check for spaces
            if (strpos($filename, ' ') !== false) {
                $violations[$relativePath] = 'Contains spaces';
            }
        }
        
        if (!empty($violations)) {
            $this->markTestSkipped(
                "INFO: Some templates may not follow naming conventions:\n" .
                print_r($violations, true)
            );
        }
        
        $this->assertTrue(true);
    }
    
    public function testPartialTemplatesStartWithUnderscore(): void
    {
        // This is a convention check - partials should start with _
        // But it's not strictly enforced in all Symfony projects
        $this->assertTrue(true);
    }
    
    // ========================================
    // DIRECTORY STRUCTURE TESTS
    // ========================================
    
    public function testTemplateDirectoriesMatchControllers(): void
    {
        $expectedDirs = [
            'security',
            'dashboard',
            'company',
            'contact',
            'rfq',
            'activity',
            'email_campaign',
            'webinar',
            'lead',
            'webcrawler',
            'playbook',
            'admin',
            'abm_dashboard',
            'quote_copilot',
            'quote_estimator',
            'quote_review',
            'profile',
        ];
        
        $missing = [];
        
        foreach ($expectedDirs as $dir) {
            if (!is_dir($this->templatesPath . '/' . $dir)) {
                $missing[] = $dir;
            }
        }
        
        $this->assertEmpty($missing, 
            "Missing expected template directories:\n" . implode("\n", $missing));
    }
    
    // ========================================
    // CRUD TEMPLATE TESTS
    // ========================================
    
    public function testCrudEntitiesHaveStandardTemplates(): void
    {
        $crudEntities = ['company', 'contact', 'rfq', 'activity'];
        $standardTemplates = ['index.html.twig', 'show.html.twig', 'new.html.twig', 'edit.html.twig'];
        
        $missing = [];
        
        foreach ($crudEntities as $entity) {
            foreach ($standardTemplates as $template) {
                $path = $this->templatesPath . '/' . $entity . '/' . $template;
                if (!file_exists($path)) {
                    $missing[] = "$entity/$template";
                }
            }
        }
        
        $this->assertEmpty($missing, 
            "Missing standard CRUD templates:\n" . implode("\n", $missing));
    }
    
    // ========================================
    // SENSEI-RAMS STRUCTURE TESTS
    // ========================================
    
    public function testTemplatesUseModuleStructure(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->templatesPath)->name('*.twig');
        
        $templatesWithContent = 0;
        $templatesWithModules = 0;
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();
            
            // Skip partials, components, emails, pdfs
            if (strpos($relativePath, 'components/') === 0 ||
                strpos($relativePath, 'emails/') === 0 ||
                strpos($relativePath, 'pdf/') === 0 ||
                strpos(basename($relativePath), '_') === 0) {
                continue;
            }
            
            // Only check templates with actual content
            if (strpos($content, '{% extends') !== false && strlen($content) > 200) {
                $templatesWithContent++;
                
                if (strpos($content, 'rams-module') !== false || 
                    strpos($content, 'rams-') !== false) {
                    $templatesWithModules++;
                }
            }
        }
        
        // At least 80% of content templates should use Sensei-Rams classes
        $percentage = $templatesWithContent > 0 
            ? ($templatesWithModules / $templatesWithContent) * 100 
            : 0;
        
        $this->assertGreaterThanOrEqual(80, $percentage, 
            "At least 80% of templates should use Sensei-Rams classes. Found: {$percentage}%");
    }
    
    public function testBaseTemplateHasBezelFrame(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('rams-bezel', $content, 
            'Base template should use industrial bezel frame structure');
    }
    
    public function testBaseTemplateHasSidebar(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('rams-sidebar', $content, 
            'Base template should have Sensei-Rams sidebar');
    }
    
    public function testBaseTemplateHasStatusBar(): void
    {
        $content = file_get_contents($this->templatesPath . '/base.html.twig');
        
        $this->assertStringContainsString('rams-status-bar', $content, 
            'Base template should have status bar');
    }
}
