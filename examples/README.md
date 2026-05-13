# Examples

These examples are the quickest way to try the primary `PdfToolkit` workflows for v1.

## Start Here

- `generate_hello_world.php`
  Creates a brand-new PDF from scratch and writes it to disk.
- `generate_hello_world_browser.php`
  Creates a brand-new PDF from scratch and streams it inline in the browser.
- `import_add_text.php`
  Imports an existing PDF, overlays text on page 1, and writes the result to disk.
- `import_add_text_browser.php`
  Imports an existing PDF, overlays text on page 1, and streams the result inline.
- `fill_form_browser.php`
  Imports an AcroForm PDF, fills configured fields, and streams the result inline.
- `list_form_fields_browser.php`
  Shows fillable form fields on top of the PDF and in a selectable sidebar.
- `application_pdf_browser.php`
  Imports an external application template, overlays fixed-position answers/signatures, and appends generated beneficiary pages.
- `commissions_report_browser.php`
  Recreates a legacy commissions statement as a native PdfToolkit report with repeating headers/footers, a paginated record table, and a final summary page.

## Sample PDFs Included

- `f1099msc.pdf`
  A real IRS form that is useful for import, overlay, and form-field testing.

## Browser Examples

The browser examples are plain PHP files. Put the project somewhere your local PHP server can read it, update the configuration variables near the top of the file, and open the file in your browser.

## Notes

- Coordinates in the public API use a top-origin model. `y = 0` means the top of the page.
- The form examples target AcroForm-style fields, not XFA workflows.
- The examples are intentionally simple and focused on the stable v1 API surface.
