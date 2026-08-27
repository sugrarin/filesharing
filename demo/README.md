# Interactive demo

`index.html` is a self-contained preview of the current application interface.
It requires no PHP, database, configuration, or sign-in.

Open `demo/index.html` from any static host (for example, GitHub Pages) to try:

- file uploads, replacement, renaming, deletion, and undo;
- categories and one-level subcategories;
- folder-link creation and revocation;
- search, date/size sorting, and file category changes.

Changes are stored only in the visitor's browser through `localStorage`. The
**Reset demo** button restores the initial data. Uploaded files are represented
by metadata only; they are not sent to a server or retained after the demo data
is reset.

The two supplied PDF files can be opened from their initial file rows. See
`THIRD_PARTY_NOTICES.md` before publishing them.
