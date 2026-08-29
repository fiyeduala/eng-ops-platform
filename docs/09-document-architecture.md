# 09 — Document & Version Architecture (Engine 3)

## 1. Model

```
documents                organization_id, number, title, description,
                         document_category_id, discipline_id, project_id, org_unit_id,
                         type, confidentiality, status_id, owner_id, current_version_id,
                         is_controlled, retention_until, tags json

document_versions        document_id, revision, revision_index, file_disk, file_path,
                         file_hash(sha256), file_size, mime_type, original_name,
                         author_id, change_summary, status_id, is_current,
                         approved_by, approved_at, superseded_at
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
signatures  organization_id, signable_type/id (approval), user_id, employee_id,
            designation_snapshot, document_version_id, action(approve|review|sign|reject),
            signed_at, ip, user_agent, signature_image_path, hash_of_signed_content
```

`designation_snapshot` stores the signatory's title **at the moment of signing**, so a later
promotion or reorganization does not rewrite history.

`hash_of_signed_content` binds the signature to the exact file bytes signed — if someone later
claims a different file was approved, the hash disproves it.

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
