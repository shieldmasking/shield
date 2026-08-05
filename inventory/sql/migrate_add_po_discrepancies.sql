-- Run once: adds po_discrepancies column to quotes table
ALTER TABLE quotes ADD COLUMN po_discrepancies TEXT NULL AFTER po_pdf_path;
