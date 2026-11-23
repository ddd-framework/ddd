# Sinks of the scan (`UntrustedSinkScanner::SINK_*`)

| Sink name | Caught calls | Go through |
|---|---|---|
| `imagick-decode` | `->readImageBlob()`, `->readImage()`, `->readImageFile()`, `->readImages()`, `->pingImage*()` | `RasterImageDecoder` |
| `xml-parse` | `->loadXML()`, `->loadHTML()`, `->loadHTMLFile()`, `simplexml_load_*()`, `new SimpleXMLElement`, `new XMLReader`, `XMLReader::XML()` / `open()` | `SafeXml` |
| `yaml-parse` | `Yaml::parse()`, `Yaml::parseFile()` | own config only, no object flags |
| `template-from-string` | `->createTemplate()` | templates from files |
| `dompdf` | `new Dompdf` | `PDFDocument::fromHTML()` |
| `eval` | `eval`, `create_function()` | — |
| `unserialize` | `unserialize()` without an `allowed_classes` key in an array literal | `allowed_classes`, or JSON |
| `shell` | `exec()`, `shell_exec()`, `system()`, `passthru()`, `popen()`, `proc_open()`, `pcntl_exec()`, backticks, `Process::fromShellCommandline()` | `IsolatedProcess` |
| `process` | `new Process(...)` | `IsolatedProcess` (or an allow-list entry for our own commands) |
| `http-client` | `curl_init()`, `curl_exec()`, `get_headers()`, `new GuzzleHttp\Client`, `HttpClient::create()` | `GuardedHttpFetchService` for URLs from outside; allow-list entries for configured hosts |
| `archive-extract` | `->extractTo()`, `new PharData`, `new Phar` | `ArchiveInspector` |
| `spreadsheet-cell` | `->setCellValue()`, `->fromArray()` | `ExcelDocument::writeCellValue()` |
| `file-path-from-request` | file functions (`file_get_contents`, `fopen`, `file_exists`, `getimagesize`, `unlink`, …) with an argument reading a property of `$request…` / `$…Dto` | `ContainedPath` |

Method sinks match by name on any object (a `->loadHtml()` of Dompdf is listed as `xml-parse`): an allow-list entry
with its reason settles such a false positive.

An allow-list entry names the file and the sink; `lines` narrows it to a range (lines move with edits, so prefer whole
files unless the file has a second, unsafe call of the same sink).
