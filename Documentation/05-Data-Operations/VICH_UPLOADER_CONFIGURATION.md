# VichUploaderBundle Configuration

**Date:** October 29, 2025  
**Status:** ✅ Configured and Ready

---

## Overview

VichUploaderBundle is configured to handle file uploads for the CRM system. It provides automatic file naming, upload handling, and deletion on entity removal.

## Upload Mappings

### 1. Compliance Documents
- **Mapping:** `compliance_documents`
- **URI Prefix:** `/uploads/compliance`
- **Directory:** `public/uploads/compliance/`
- **Use Case:** ISO certifications, audit reports, compliance PDFs
- **Entity:** `ComplianceDocument`
- **Max File Size:** 50 MB (configurable)

### 2. General Documents
- **Mapping:** `documents`
- **URI Prefix:** `/uploads/documents`
- **Directory:** `public/uploads/documents/`
- **Use Case:** Quotes, estimates, FTA packs, DFM reports, cost breakdowns, exceptions reports, sourcing risk, audit trails, onboarding packs
- **Entities:** `Quote`, `Estimate` (via ComplianceDocument relation)
- **Max File Size:** 50 MB

### 3. Case Study PDFs
- **Mapping:** `case_study_pdfs`
- **URI Prefix:** `/uploads/case-studies`
- **Directory:** `public/uploads/case-studies/`
- **Use Case:** Marketing case studies, customer success stories
- **Entity:** `CaseStudy`
- **Max File Size:** 20 MB

### 4. BOM Files
- **Mapping:** `bom_files`
- **URI Prefix:** `/uploads/bom`
- **Directory:** `public/uploads/bom/`
- **Use Case:** CSV/Excel BOM uploads for Quote Co-Pilot
- **Entity:** `Quote` (temporary storage)
- **Max File Size:** 10 MB
- **Allowed Formats:** CSV, XLS, XLSX, JSON

---

## File Naming Strategy

**Namer:** `SmartUniqueNamer`

This namer generates unique filenames to prevent collisions:
- Preserves original file extension
- Adds unique suffix based on timestamp and random string
- Example: `iso9001_certificate.pdf` → `iso9001_certificate_67ab45ef12cd.pdf`

---

## Configuration Options

### Delete on Update
```yaml
delete_on_update: true
```
When a new file is uploaded to replace an existing one, the old file is automatically deleted.

### Delete on Remove
```yaml
delete_on_remove: true
```
When the entity is deleted, the associated file is also removed from the filesystem.

### Inject on Load
```yaml
inject_on_load: false
```
Files are not automatically loaded when entities are fetched (performance optimization).

---

## Usage in Entities

### ComplianceDocument Entity

```php
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity]
#[Vich\Uploadable]
class ComplianceDocument
{
    #[Vich\UploadableField(mapping: 'compliance_documents', fileNameProperty: 'fileName', size: 'fileSize')]
    private ?File $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function setFile(?File $file = null): void
    {
        $this->file = $file;
        
        if (null !== $file) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFileName(?string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileSize(?int $fileSize): void
    {
        $this->fileSize = $fileSize;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }
}
```

---

## Usage in Forms

### Upload Form Example

```php
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Vich\UploaderBundle\Form\Type\VichFileType;

public function buildForm(FormBuilderInterface $builder, array $options): void
{
    $builder
        ->add('file', VichFileType::class, [
            'required' => false,
            'allow_delete' => true,
            'download_uri' => true,
            'download_label' => 'Download current file',
            'asset_helper' => true,
            'label' => 'Upload Document (PDF, max 50 MB)'
        ]);
}
```

---

## Usage in Controllers

### Upload Example

```php
#[Route('/compliance-document/upload', name: 'compliance_document_upload', methods: ['POST'])]
public function upload(Request $request, EntityManagerInterface $em): Response
{
    $document = new ComplianceDocument();
    $form = $this->createForm(ComplianceDocumentType::class, $document);
    
    $form->handleRequest($request);
    
    if ($form->isSubmitted() && $form->isValid()) {
        // VichUploader automatically handles the file upload
        $em->persist($document);
        $em->flush();
        
        $this->addFlash('success', 'Document uploaded successfully');
        
        return $this->redirectToRoute('compliance_document_list');
    }
    
    return $this->render('compliance_document/upload.html.twig', [
        'form' => $form->createView()
    ]);
}
```

### Download Example

```php
use Vich\UploaderBundle\Handler\DownloadHandler;

#[Route('/compliance-document/{id}/download', name: 'compliance_document_download')]
public function download(
    ComplianceDocument $document, 
    DownloadHandler $downloadHandler
): Response
{
    return $downloadHandler->downloadObject($document, 'file');
}
```

---

## Usage in Templates

### Display Download Link

```twig
{% if document.fileName %}
    <a href="{{ vich_uploader_asset(document, 'file') }}" 
       download="{{ document.fileName }}"
       class="btn btn-primary">
        Download {{ document.fileName }}
    </a>
{% endif %}
```

### File Upload Form

```twig
{{ form_start(form) }}
    {{ form_row(form.file) }}
    
    <button type="submit" class="btn btn-success">Upload</button>
{{ form_end(form) }}
```

---

## Security Considerations

### 1. File Type Validation

Add validation in forms:
```php
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\File(
    maxSize: '50M',
    mimeTypes: ['application/pdf', 'application/x-pdf'],
    mimeTypesMessage: 'Please upload a valid PDF document'
)]
private ?File $file = null;
```

### 2. Access Control

Protect download routes with security voters:
```php
#[Route('/compliance-document/{id}/download', name: 'compliance_document_download')]
#[IsGranted('VIEW', subject: 'document')]
public function download(ComplianceDocument $document, DownloadHandler $downloadHandler): Response
{
    return $downloadHandler->downloadObject($document, 'file');
}
```

### 3. Upload Directory Permissions

Ensure web server has write permissions:
```bash
chmod 755 public/uploads/
chmod 755 public/uploads/compliance/
chmod 755 public/uploads/documents/
chmod 755 public/uploads/case-studies/
chmod 755 public/uploads/bom/
```

On Windows (PowerShell):
```powershell
icacls "public\uploads" /grant "IIS_IUSRS:(OI)(CI)M"
```

---

## File Size Limits

### PHP Configuration

Edit `php.ini`:
```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
memory_limit = 256M
```

### Nginx Configuration

Edit `nginx.conf`:
```nginx
client_max_body_size 50M;
```

### Apache Configuration

Edit `.htaccess`:
```apache
php_value upload_max_filesize 50M
php_value post_max_size 50M
```

---

## Storage Optimization

### 1. Clean Up Orphaned Files

Create a console command to remove files not referenced in database:

```php
#[AsCommand(name: 'app:uploads:cleanup')]
class CleanupUploadsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uploadDir = $this->parameterBag->get('kernel.project_dir') . '/public/uploads/compliance/';
        $files = glob($uploadDir . '*');
        
        $dbFiles = $this->complianceDocumentRepo->createQueryBuilder('d')
            ->select('d.fileName')
            ->getQuery()
            ->getResult();
        
        $dbFileNames = array_column($dbFiles, 'fileName');
        
        $orphanedFiles = array_diff($files, $dbFileNames);
        
        foreach ($orphanedFiles as $file) {
            unlink($file);
            $output->writeln("Deleted orphaned file: $file");
        }
        
        return Command::SUCCESS;
    }
}
```

### 2. Archive Old Files

Move files older than 1 year to archive storage:

```php
public function archiveOldFiles(): void
{
    $oneYearAgo = new \DateTime('-1 year');
    
    $oldDocuments = $this->complianceDocumentRepo->createQueryBuilder('d')
        ->where('d.uploadedAt < :oneYearAgo')
        ->setParameter('oneYearAgo', $oneYearAgo)
        ->getQuery()
        ->getResult();
    
    foreach ($oldDocuments as $document) {
        $sourcePath = $this->projectDir . '/public' . $document->getFilePath();
        $archivePath = $this->projectDir . '/archive/uploads/' . basename($document->getFilePath());
        
        if (file_exists($sourcePath)) {
            rename($sourcePath, $archivePath);
        }
    }
}
```

---

## Testing

### Unit Test Example

```php
public function testFileUpload(): void
{
    $document = new ComplianceDocument();
    
    $uploadedFile = new UploadedFile(
        __DIR__ . '/fixtures/test.pdf',
        'test.pdf',
        'application/pdf',
        null,
        true // Test mode
    );
    
    $document->setFile($uploadedFile);
    $document->setName('ISO 9001 Certificate');
    
    $this->entityManager->persist($document);
    $this->entityManager->flush();
    
    $this->assertNotNull($document->getFileName());
    $this->assertFileExists($this->projectDir . '/public/uploads/compliance/' . $document->getFileName());
}
```

---

## Troubleshooting

### Issue: "Failed to upload file"

**Solution:** Check directory permissions and PHP upload settings.

```bash
# Check permissions
ls -la public/uploads/compliance/

# Fix permissions
chmod 755 public/uploads/compliance/
```

### Issue: "File too large"

**Solution:** Increase PHP upload limits in `php.ini`.

### Issue: "File not found after upload"

**Solution:** Verify VichUploader mapping name matches entity annotation.

---

## Roadmap

### Phase 1 (Current) ✅
- Basic upload/download functionality
- Automatic file naming
- Delete on update/remove
- 4 upload mappings configured

### Phase 2 (Q1 2026)
- ⏳ AWS S3 integration for cloud storage
- ⏳ Image thumbnail generation
- ⏳ Virus scanning integration (ClamAV)
- ⏳ CDN integration for faster downloads

### Phase 3 (Q2 2026)
- ⏳ Multi-file upload support
- ⏳ Drag-and-drop upload UI
- ⏳ Progress bar for large files
- ⏳ Automatic file compression

---

**Document End**
