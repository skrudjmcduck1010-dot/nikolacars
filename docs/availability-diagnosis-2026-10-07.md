# Availability diagnosis — 2026-10-07

Read-only investigation using authenticated HOSTiQ/cPanel, live FTPS and
downloaded current access logs. No hosting/security settings were changed,
no jobs stopped, and no support message was sent.

## Confirmed resource pressure

cPanel Resource Usage shows limits of CPU SPEED 100%, NPROC 40, EP 25 and
physical memory 1.5 GB. The hourly history for October 7 includes:

| Hour (panel time) | Average CPU | CPU faults | NPROC faults |
| --- | ---: | ---: | ---: |
| 20:00–21:00 | 83% | 164 | 0 |
| 21:00–22:00 | 68% | 154 | 7 |
| 22:00–23:00 | 42% | 49 | 0 |
| 23:00–00:00 (partial hour) | 67% | 95 | 0 |

Memory and EP faults are zero in these intervals. Disk usage was 16.38/25 GB
and inodes 137858/200000, so neither quota was full. Current low utilization
does not negate the recorded historical faults. No process snapshots were
available for the day; the exact heavy process has not been identified.

CloudLinux documents that CPU limits slow scripts and process limits can
cause HTTP 500/503: https://docs.cloudlinux.com/cloudlinuxos/limits/ .

## Real HTTP/API failures, distinct from connection failures

The current SSL access logs cover roughly 15:10–23:52 (explicit +0300).
They contain 107 HTTP 503 responses for the site, and 73 HTTP 503 plus one
HTTP 500 for the warehouse. These are request counts, not outage counts.
Some later errors can overlap releases; not every 503 is necessarily caused
by resource exhaustion. The largest warehouse 503 cluster is at 21:39–21:41,
within the hour where NPROC faults were recorded.

Affected warehouse URLs include browser-loader claim/heartbeat and storefront
catalog/product endpoints. The site's Laravel log also records warehouse
HTTP 503 responses and 20-second cURL 28 timeouts. Its configured timezone is
UTC, so its timestamps must not be compared directly to +0300 access logs.

The warehouse log tail contains recurring HTTP 404 errors and failures of
`customer-orders:sync-tracking-history`, plus Nova Post API timeouts. These
require a separate integration review; they do not establish which process
exhausted CPU/NPROC. The local schedule contains many background integrations
and feed/queue tasks with overlap guards. Live scheduling/process workload
must be checked before changing frequencies or stopping jobs.

## The later TCP outage was not a complete server outage

Local checks could not establish TCP connections to site HTTP/HTTPS or
hosting FTP, while Google worked and DNS resolved. In the 23:41–23:44 window,
the site access log still contains 15 HTTP 200 and four HTTP 403 responses
from other clients, and the warehouse continued returning HTTP 200.
No local requests that failed before connecting are expected in access logs.
Connectivity subsequently recovered without configuration changes.

This favors source-IP filtering or a network-route problem over a complete
web-server outage, but does not prove either cause. The exposed cPGuard WAF
log's newest entry is October 6, and bot-attack log's newest entry October 1;
neither explains the October 7 TCP outage. cPanel port 2083 was reachable.
Server-wide firewall/connection-throttling logs are not exposed here.

## Next actions requiring coordination or implementation authority

1. Ask HOSTiQ to correlate server firewall/CSF/LFD, LiteSpeed connection
   throttling and network events with October 7 23:40–23:44 +0300. Supply the
   affected source IP privately only with user approval.
2. Identify top processes at CPU/NPROC peaks, and verify the live cron/queue
   schedule. Optimize or stagger proven heavy tasks before considering a
   different hosting plan. Do not disable security globally.
3. Investigate repeated tracking-history integration failures separately.

Raw application-log tails remain in ignored
`.codex-tmp/availability-20261007/`. Access-log downloads remain in the user's
Downloads directory. Raw logs can contain customer data and must not be
committed or sent to third parties without approval.
