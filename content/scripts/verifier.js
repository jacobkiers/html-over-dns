class Verifier {
    /***
     * @param content Content
     */
    async verify(content) {
        if (!window.isSecureContext) return;
        if (typeof TextEncoder === "undefined") return;
        if (typeof crypto === "undefined") return;
        const encoder = new TextEncoder();
        const digestBuffer = await crypto.subtle.digest(content.index.hashAlgorithm, encoder.encode(content.content));

        const hashArray = Array.from(new Uint8Array(digestBuffer));                     // convert buffer to byte array
        const digest = hashArray.map(b => b.toString(16).padStart(2, '0')).join(''); // convert bytes to hex string

        if (content.index.hash === digest) {
            document.getElementById("verification").innerHTML =
                "The hash of the content is verified to correspond with the hash" +
                " in the metadata. You are now reading exactly what was intended.";
        }
    }
}