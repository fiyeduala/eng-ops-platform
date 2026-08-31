# 09 — Document & Version Architecture (Engine 3)

## 1. Model

```
documents                organization_id, number, title, description,
                         document_category_id, discipline_id, project_id, org_unit_id,
                         kind, confidentiality, status_id, owner_id, current_version_id,
                         is_controlled, retention_until, next_review_on, tags json,
                         locked_by, locked_at, lock_expires_at

document_versions        document_id, revision, revision_index, is_draft_series,
                         file_disk, file_path, file_hash(sha256), file_size,
                         mime_type, original_name,
                         content_html, content_json, word_count,
                         author_id, change_summary, status_id, is_current,
                         approved_by, approved_at, superseded_at,
                         derived_from_version_id
```

The `documents` row is the **identity and register entry**; every file lives on a
`document_versions` row. The document itself never holds a file path other than the denormalized
`current_version_id` pointer.

## 2. Immutability (§26)

> Never overwrite an approved document revision.

```
ENG-ELEC-001
  ├── Revision A  · superseded 2026-03-04 · approved by Eng. Manager · sha256 …
  ├── Revision B  · superseded 2026-06-11 · approved by Eng. Manager · sha256 …
  └── Revision C  · CURRENT · under review
```

Enforced by:

- No update route touches `file_path` on a version whose status is `approved`.
- A new revision is a new row; the previous row gets `is_current=false`, `superseded_at=now()`.
- `sha256` is recorded at upload; a nightly integrity job re-hashes a sample and reports mismatches.
- `is_current` uniqueness per document is maintained inside a transaction.

Each revision independently keeps: file · author · date · change summary · reviewer · approver ·
approval status · signature · full audit trail (§26).

Revision sequencing is configurable per category: alphabetic (`A, B, C… AA`) or numeric
(`0, 1, 2…`), with an optional draft series (`P01, P02`) that promotes to the issued series.

## 3. Storage & security (§67)

```
storage/app/private/organizations/{org}/documents/{doc}/{version}/{ulid}.{ext}
```

- **Private disk only.** Nothing document-related is reachable from `public/`.
- `public/storage` symlink is used only for branding assets (logos), never for records.
- Download path: `GET /documents/{document}/versions/{version}/download`
  → policy check (five gates) → `download` permission → audit entry → streamed response.
- Filenames are ULIDs on disk; the original name is metadata only — no path traversal surface.

**Upload validation:** MIME sniffed from content (not the client header) · extension allow-list ·
size cap per category · filename sanitised · executable/script types rejected outright
(`php, phtml, exe, dll, sh, bat, cmd, js, jar, msi, com, scr, vbs, ps1`) · archives optionally
scanned or rejected · SVG rejected or sanitised (XSS vector).

## 4. Access control (§27)

Document visibility is independently controllable, on top of the five gates:

| Setting | Reach |
|---------|-------|
| Organization-wide | any user with `document.view` |
| Department-only | owning `org_unit` subtree |
| Project-wide | active members of `project_id` |
| Project department | members whose unit ⊆ the named department |
| Specific team | named `org_unit` of type `team` |
| Specific users | `record_permissions` rows |
| Restricted / confidential | ACL only; project membership is not sufficient |

Always evaluated server-side, on every read, download, preview, export and search hit.

## 5. Review, approval and signing (§28)

Documents use the workflow engine — there is no separate document approval system.

```
signatures  organization_id, approval_id, signable_type/id (the record signed),
            document_version_id, user_id, employee_id,
            name_snapshot, designation_snapshot, action, signed_at,
            ip, user_agent, signature_image_path, content_hash,
            provider(internal|…), provider_reference json
```

**When a signature is written.** `RecordDocumentSignature` listens for `ApprovalDecided` and writes
one row per approver, at the moment they decide — so a three-stage route produces the three lines an
engineering title block has always carried (checked by, reviewed by, approved by), not a single
stamp from whoever happened to close the route.

The listener, rather than the engine, makes that decision. `WorkflowEngine` names no module: a
purchase requisition approved by a manager wants an audit trail, not a signature block. "Approvals
of *this* kind of record are signatures" is a document-module judgement and lives in the document
module's wiring, beside the notification listeners that make the same call.

**Only `approve` signs.** A rejection is fully recorded on the `approvals` row with its comment, but
a signature is an affirmative endorsement, and a signature table where some rows mean the opposite
is a trap for anybody who later counts them. Delegation and abstention sign nothing either.

`name_snapshot` and `designation_snapshot` store the signatory's name and title **at the moment of
signing** — the title is read through their *position*, since a job title is a record with its own
history rather than a column on a person — so a later promotion or reorganization does not rewrite
who approved a drawing as what they have since become.

`content_hash` binds the signature to the exact bytes signed: a file revision's stored sha256, or
the sha256 of the approved HTML for a native one (`DocumentVersion::contentHash()`). An approved
revision cannot be edited, so signature and content can only disagree if the stored bytes were
changed underneath it — which the revision page states in words rather than leaving two hex strings
to be compared by eye.

Signatures are written once. There is no update path and no soft delete: withdrawing an approval is
a new event, not an edit to the old one.

### Legal scope statement (§28)

This is an **internal approval and electronic-signature facility**: it records who approved what,
when, on which exact file version, with an auditable trail. It is **not** a qualified/advanced
electronic signature under eIDAS, ESIGN/UETA, or any specific national statute, and it does not
issue certificate-backed digital signatures. Where a jurisdiction requires a qualified signature,
integrate a certified provider (DocuSign, Adobe Sign, a national CA) — the architecture supports
this via a `SignatureProvider` interface, with the internal provider as the default. The UI labels
internal signatures as *"Internal approval signature"* to avoid overstating their legal weight.

## 6. Transmittals (§38)

```
transmittals       number, project_id, to_party_type/id (client|vendor|org_unit),
                   purpose(for_approval|for_construction|for_information|for_review),
                   issued_by, issued_at, due_response_on, status_id, cover_note
transmittal_items  transmittal_id, document_version_id, copies, media, remarks
```

A transmittal pins **specific versions**, so "what did we issue to the client on 4 March" is always
answerable. Transmittals generate a PDF cover sheet and can require acknowledgement.

## 7. Registers

The Document Register and Drawing Register are **filtered views** of the same tables — never
separate storage. Register columns: number · title · discipline · category · current revision ·
status · author · approver · issued date · next review · transmittal history.

Registers respect visibility, so two users legitimately see different register contents.

**`requires_approval` is enforced, not decorative.** A register may hold documents nobody approves —
correspondence received, vendor data, uncontrolled reference copies. Unticking the box means the
document is issued by its author the moment it is submitted, and `Document::requiresWorkflow()`
returns false so the engine does **not** fall through to the tenant's default route. That
distinction matters: `resolveWorkflowDefinition()` returning null means "no preference, use the
module default", which is right for a memo; `requiresWorkflow()` returning false means "do not route
this, and do not fall back". Without the second, an uncontrolled register would quietly send every
incoming client letter out for technical review.

An unrouted document is still not confused with an approved one: `DocumentService` writes to the
timeline that it was issued without review, and no signature is recorded, because nobody signed it.

## 8. Preview

- PDFs and images preview inline through the authorizing controller with
  `Content-Disposition: inline` and a strict `Content-Security-Policy: sandbox` header.
- Office formats offer download only in V1 (no third-party viewer service, no data leaving the
  server).
- Every preview is audit-logged exactly like a download.

## 9. Templates & generation (§65, §66)

`templates` hold HTML with placeholders (`{{document.number}}`, `{{project.name}}`,
`{{signatories}}`) for letters, purchase orders, invoices, meeting minutes, project reports, HSE
reports, inspection reports, transmittals and certificates.

PDF generation uses a pure-PHP renderer (Dompdf) so no headless browser is required on cPanel.
Generated PDFs carry logo · company information · document number · revision · date · project ·
signatories · approval history. DOCX generation (PhpWord) is available for editable deliverables.

Rendering is sandboxed: templates are data, rendered through a restricted placeholder resolver —
never `eval` or raw Blade compilation of user input.

## 10. Native authoring and the .docx round trip (§65)

The brief was for documents that *replace Word in a sense, or integrate with it*. Both halves are
built, and the honest boundary between them is stated here rather than discovered later.

**Two kinds of document, one register.** `documents.kind` is `native` or `file`:

| | native | file |
|---|---|---|
| Where the content lives | `content_html` + `content_json` on the version | private disk, `file_path` + `file_hash` |
| Authored | in the browser (TipTap), autosaved | produced elsewhere, uploaded |
| Searchable, diffable, templatable | yes | no — the system does not pretend to read a vendor's PDF |
| Exports | .docx, PDF | the original bytes, plus PDF where meaningful |

Drawings, vendor data and scanned correspondence stay `file`. Procedures, specifications, method
statements and letters are `native`, and those are the ones that displace Word.

**Editing is check-out locked, not co-edited.** One person holds the lock (`locked_by`,
`locked_at`, `lock_expires_at`); it expires after `documents.checkout_hours` (default 8) so a laptop
closed on a Friday does not freeze a document until Monday. Real-time co-editing is deliberately out
of V1: it needs a WebSocket server, which §1 bars from being mandatory on cPanel. The editor
autosaves to `PUT /documents/{document}/versions/{version}`, and the service refuses any write to an
approved revision whoever asks.

**Content is sanitized on the way in, always.** `ContentSanitizer` (HTMLPurifier) runs on every
save, every import and every template render: a fixed element and CSS-property allow-list, no
remote resources, images only as `data:` URIs or paths under this organization's own storage.
Editor input is untrusted input.

**Import.** `DocxImporter` (PhpWord) reads a .docx body — headings, paragraphs, runs, lists, tables,
links — into clean HTML. Tracked changes, comments, headers/footers, embedded objects and exact
pagination do **not** survive, and are dropped visibly rather than accepted silently. **The original
.docx is always kept alongside the extracted HTML**, so a lossy import is an inconvenience rather
than a data-loss event.

**Export.** `DocxExporter` writes .docx and `PdfRenderer` (Dompdf) writes PDF, both from the same
sanitized HTML, with the header, footer and title block coming from the **Template** rather than
from whatever the author's copy of Word happened to contain. That is the argument for authoring
here: the controlled document gets its identity from the company's configuration, every time.

**What is not claimed.** This is not byte-faithful Word interoperability, and nothing in the UI
suggests it is. The contract is: bring your Word content in, keep working on it here, send it back
out as .docx or PDF.
