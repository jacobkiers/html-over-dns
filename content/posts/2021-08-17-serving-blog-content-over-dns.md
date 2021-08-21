+++
title = HTML over DNS: Serving Blog Content Over DNS
date = 2021-08-15
author = Jacob Kiers
+++

## What's up?

You might not be able to see it immediately, but the content of this page is served over DNS.

This works because of [DNS over HTTPS] for which there is an [API from Cloudflare].

Comments at [Hacker News, apparently][HN].

## How it works

That API is used to load the contents of this page, essentially like this:

```js
fetch("https://cloudflare-dns.com/dns-query?ct=application/dns-json&type=TXT&name=post.hod.experiments.jacobkiers.net");
```

The content itself is served over DNS, using CoreDNS, with these contents:

```hcl
hod.experiments.jacobkiers.net.:53 {
    log
    auto hod.experiments.jacobkiers.net. {
	directory /etc/coredns/zones/
        reload 10s
    }
}
```

This feeds into a zone file, which looks like this:

```dns
$TTL 5m	; Default TTL
@	IN	SOA	experiments.jacobkiers.net.	postmaster.jacobkiers.net. (
	2021081612	; serial
	1h		; slave refresh interval
	15m		; slave retry interval
	1w		; slave copy expire time
	1h		; NXDOMAIN cache time
	)

$ORIGIN hod.experiments.jacobkiers.net.

;
; domain name servers
;
@	IN	NS  experiments.jacobkiers.net.


;; START BLOG RECORDS
; posts-2021-08-17-serving-blog-content-over-dns-md
posts-2021-08-17-serving-blog-content-over-dns-md	60	IN	TXT	"t=text/markdown;c=3;h=2fd63f0f408ad1336283999d0487ced9;m=eyJ0aXRsZSI6IlNlcnZpbmcgYmxvZyBjb250ZW50IG92ZXIgRE5TIiwiZGF0ZSI6IjIwMjEtMDgtMTUiLCJhdXRob3IiOiJKYWNvYiBLaWVycyJ9"
0.2fd63f0f408ad1336283999d0487ced9	60	IN	TXT	"WW91IG1pZ2h0IG5vdCBiZSBhYmxlIHRvIHNlZSBpdCBpbW1lZGlhdGVseSwgYnV0IHRoZSBjb250ZW50IG9mIHRoaXMgcGFnZSBpcyB2ZXJ2ZWQgb3ZlciBETlMuCgpUaGlzIHdvcmtzIGJlY2F1c2Ugb2YgdGhlIG5ldyBETlMtb3Zlci1IVFRQIHN1cHBvcnQsIHdoaWNoLCBhdCBsZWFzdCBhdCBDbG91ZGZsYXJlLCBhbHNvIGhhcy"
1.2fd63f0f408ad1336283999d0487ced9	60	IN	TXT	"BhbiBBUEkuCgpUaGF0IEFQSSBpcyB1c2VkIHRvIGxvYWQgdGhlIGNvbnRlbnRzIG9mIHRoaXMgcGFnZSwgZXNzZW50aWFsbHkgbGlrZSB0aGlzOgoKYGBganMKZmV0Y2goImh0dHBzOi8vY2xvdWRmbGFyZS1kbnMuY29tL2Rucy1xdWVyeT9jdD1hcHBsaWNhdGlvbi9kbnMtanNvbiZ0eXBlPVRYVCZuYW1lPXBvc3QuaG9kLmV4cGVy"
2.2fd63f0f408ad1336283999d0487ced9	60	IN	TXT	"aW1lbnRzLmphY29ia2llcnMubmV0Iik7CmBgYAoKUGxlYXNlIHNlZSB0aGUgW3NvdXJjZSBjb2RlXSBmb3IgdGhlIGRldGFpbHMgb2YgaG93IGl0IHdvcmtzLgoKW3NvdXJjZSBjb2RlXTogaHR0cHM6Ly9naXRodWIuY29tL2phY29ia2llcnMvaHRtbC1vdmVyLWRucwo="
```

These records are base64 encoded content, so when concatenated and decoded, they give the content of the posts.

Please see the [source code] for the details.

## FAQ

### Why, though?

In short: just because I could. It was one of those ideas I was wondering idly about, and I decided to just try it.

### Has it any practical use?

It is not intended to have any. Since DNS records are fairly small, serving images or something would quickly start
consuming 100s of requests per second. I wouldn't want to do that to Cloudflare.

It would be an interesting experiment to see how feasible that is.

[source code]: https://github.com/jacobkiers/html-over-dns "Yes, the title is a pun..."
[DNS over HTTPS]: https://en.wikipedia.org/wiki/DNS_over_HTTPS
[API from Cloudflare]: https://developers.cloudflare.com/1.1.1.1/dns-over-https/json-format
[HN]: https://news.ycombinator.com/item?id=28218406
