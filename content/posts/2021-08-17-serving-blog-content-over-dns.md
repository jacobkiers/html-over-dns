+++
title = Serving blog content over DNS
date = 2021-08-15
author = Jacob Kiers
+++

You might not be able to see it immediately, but the content of this page is verved over DNS.

This works because of the new DNS-over-HTTP support, which, at least at Cloudflare, also has an API.

That API is used to load the contents of this page, essentially like this:

```js
fetch("https://cloudflare-dns.com/dns-query?ct=application/dns-json&type=TXT&name=post.hod.experiments.jacobkiers.net");
```

Please see the [source code] for the details of how it works.

[source code]: https://github.com/jacobkiers/html-over-dns