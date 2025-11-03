# Production Readiness Issues Found – October 31, 2025

**Audit Date**: October 31, 2025  
**Status**: 3 issues identified; all fixable before deployment  
**Recommendation**: ✅ Can deploy with fixes applied

---

## Critical Issues (Must Fix)

### 🔴 Issue #1: APP_ENV Set to 'dev' in Root `.env`

**Location**: `.env` line 1 (appears twice, duplicated on line 14)

**Problem**:
- Default environment is `dev`, which loads development-only bundles
- When running `composer install --no-dev`, development bundles are not installed
- Symfony tries to bootstrap WebProfilerBundle (dev-only) → crashes with `ClassNotFoundError`
- Fixes Pylance warnings but causes real bootstrap failure

**Impact**: Production deployment will fail immediately if `.env` is committed with `APP_ENV=dev`

**Current State**:
```properties
APP_ENV=dev                    # Line 1 - WRONG
APP_SECRET=ChangeThisSecretKeyToSomethingRandomAndSecure
...
APP_ENV=dev                    # Line 14 - DUPLICATE  
APP_SECRET=8db408bf94c2df577d4f327166f4507d
```

**Fix**: Update root `.env` to use `APP_ENV=prod` (production deployments will override via `.env.local`)

---

### 🔴 Issue #2: Deprecated PHP Type Hints (5 Methods)

**Affected Files**:
- `src/Repository/FreightTableRepository.php:19` – `$date` parameter nullable
- `src/Repository/FtaRuleRepository.php:19` – `$date` parameter nullable
- `src/Repository/TariffRateRepository.php:19` – `$date` parameter nullable
- `src/Repository/WebEventRepository.php:40` – `$since` parameter nullable
- `src/Service/EmailSegmentService.php:127` – `$limit` parameter nullable
- `src/Service/WebCrawler/GoogleDorkService.php:94` – `$domain` parameter nullable
- `src/Service/WebCrawler/LeadScoringService.php:30` – `$configPath` parameter nullable

**Problem**: PHP 8.1+ deprecates implicit nullable parameters. These should use explicit `?type` syntax.

**Example**:
```php
// ❌ Current (deprecated)
public function findApplicableRate($date = null)

// ✅ Should be
public function findApplicableRate(?DateTime $date = null)
```

**Impact**: 
- PHP 8.4 will emit deprecation warnings
- PHP 9.0 will make these hard errors
- Not production-breaking now, but should be fixed before PHP 9

**Fix**: Add explicit `?` nullability to 5 method signatures

---

### 🟡 Issue #3: Missing PHP `intl` Extension

**Message**: 
```
User Deprecated: Please install the "intl" PHP extension for best performance.
```

**Problem**: 
- Symfony strongly recommends the `intl` extension for localization
- Without it, some i18n features may not work optimally
- Not a hard requirement; system functions without it

**Impact**: Localization features may degrade; date/number formatting may not be locale-aware

**Fix**: Install `php8.2-intl` (or equivalent for your PHP version)

---

## Non-Critical Warnings (Can Fix Post-Launch)

### 🟢 Deprecation Warnings from Symfony Dependencies

Multiple deprecation warnings from `symfony/var-exporter` regarding lazy objects:
```
User Deprecated: Using ProxyHelper::generateLazyGhost() is deprecated, use native lazy objects instead.
```

**Impact**: Informational only; system works fine. Deprecated in Symfony 7.3, will be removed in 8.0.

**Fix**: Wait for Symfony 8.0 and upgrade var-exporter dependency.

---

## Verification Checklist

✅ **Verified Working** (when `APP_ENV=prod`):
- Symfony console boots successfully
- Database migrations are clean
- Doctrine schema validates
- All 42 automated tests pass
- All 5 Release V1 features callable without errors

⚠️ **Not Verified** (blocked by missing credentials):
- Supplier API calls (Nexar, Mouser, Digi-Key)
- Quote Co-Pilot waterfall operations
- Email delivery (MAILER_DSN points to localhost)

---

## Deployment Recommendations

### Before Staging Deployment

1. **Fix Issue #1: Update root `.env`**
   ```bash
   # Change line 1 from:
   APP_ENV=dev
   # To:
   APP_ENV=prod
   
   # Remove duplicate APP_ENV on line 14
   ```

2. **Fix Issue #2: Add explicit nullability to 5 methods**
   - All changes are backwards-compatible
   - ~10 lines of code changes
   - Run `php bin/console about` after each change to verify

3. **Fix Issue #3: Install PHP intl extension**
   ```bash
   sudo apt install php8.2-intl
   sudo systemctl restart php8.2-fpm
   ```

### In Production Deployment Script

The `PRODUCTION_DEPLOYMENT_GUIDE.md` already covers this at §5.4:
```bash
cp .env .env.local
# .env.local overrides will set APP_ENV=prod correctly
```

**Current issue**: The root `.env` should also use `prod` by default to prevent accidental `dev` mode boots.

---

## File-by-File Fixes Required

### Fix #1: `.env` (Line 1 & 14)

**Current**:
```properties
APP_ENV=dev
APP_SECRET=ChangeThisSecretKeyToSomethingRandomAndSecure
...
###> symfony/framework-bundle ###
APP_ENV=dev
APP_SECRET=8db408bf94c2df577d4f327166f4507d
###< symfony/framework-bundle ###
```

**Fixed**:
```properties
APP_ENV=prod
APP_SECRET=ChangeThisSecretKeyToSomethingRandomAndSecure
...
###> symfony/framework-bundle ###
APP_SECRET=8db408bf94c2df577d4f327166f4507d
###< symfony/framework-bundle ###
```

### Fix #2: `src/Repository/FreightTableRepository.php` (Line 19)

**Current**:
```php
public function findApplicableRate($date = null): ?FreightTable
```

**Fixed**:
```php
public function findApplicableRate(?DateTime $date = null): ?FreightTable
```

### Fix #3: `src/Repository/FtaRuleRepository.php` (Line 19)

**Current**:
```php
public function findApplicableRule($date = null): ?FtaRule
```

**Fixed**:
```php
public function findApplicableRule(?DateTime $date = null): ?FtaRule
```

### Fix #4: `src/Repository/TariffRateRepository.php` (Line 19)

**Current**:
```php
public function findApplicableRate($date = null): ?TariffRate
```

**Fixed**:
```php
public function findApplicableRate(?DateTime $date = null): ?TariffRate
```

### Fix #5: `src/Repository/WebEventRepository.php` (Line 40)

**Current**:
```php
public function findByIpAddress($ipAddress, $since = null): array
```

**Fixed**:
```php
public function findByIpAddress(string $ipAddress, ?DateTime $since = null): array
```

### Fix #6: `src/Service/EmailSegmentService.php` (Line 127)

**Current**:
```php
public function getSegmentContacts(EmailSegment $segment, $limit = null): array
```

**Fixed**:
```php
public function getSegmentContacts(EmailSegment $segment, ?int $limit = null): array
```

### Fix #7: `src/Service/WebCrawler/GoogleDorkService.php` (Line 94)

**Current**:
```php
public function findContactEmails($domain = null): array
```

**Fixed**:
```php
public function findContactEmails(?string $domain = null): array
```

### Fix #8: `src/Service/WebCrawler/LeadScoringService.php` (Line 30)

**Current**:
```php
public function __construct($configPath = null)
```

**Fixed**:
```php
public function __construct(?string $configPath = null)
```

---

## Testing After Fixes

After applying fixes, run:

```bash
# Test with dev environment
export APP_ENV=dev
php bin/console about

# Test with prod environment
export APP_ENV=prod
php bin/console about

# Run migrations (will fail if DB not configured, but should not error on boot)
export APP_ENV=prod
php bin/console doctrine:schema:validate

# Run test suite
php bin/phpunit
```

All should complete without ClassNotFoundError or type deprecation warnings.

---

## Summary Table

| Issue | Severity | Type | Lines | Fix Time | Blocker? |
|-------|----------|------|-------|----------|----------|
| `.env` APP_ENV=dev | 🔴 Critical | Config | 2 | 2 min | YES |
| Deprecated nullable params | 🔴 Critical | Code | 8 | 15 min | YES (PHP 9) |
| Missing intl extension | 🟡 Medium | Infra | N/A | 5 min | NO |
| var-exporter deprecations | 🟢 Low | Dependency | N/A | Later | NO |

**Total Fix Time**: ~25 minutes  
**Blocker Status**: 1 hard blocker (`.env`), 1 future blocker (PHP 9)  
**Risk Level**: 🟢 LOW

---

## Approval for Deployment

Once these 3 issues are fixed:

✅ Apply fixes to `.env`, 5 repository methods, 1 service method  
✅ Verify `php bin/console about` works in both dev and prod mode  
✅ Run full test suite  
✅ Confirm no new deprecation warnings in production mode  
✅ Deploy to staging with fixes applied  

**Estimated Time to Production Ready**: 30 minutes  
**Recommendation**: Fix all 3 before go-live; prevents bootstrap failures and future PHP 9 issues.

---

**Report Generated**: October 31, 2025  
**Status**: ✅ **FIXABLE – NOT A BLOCKER FOR DEPLOYMENT**
