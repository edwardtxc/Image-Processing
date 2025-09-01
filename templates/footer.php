    </div>
    <script>
    // Shared helper for fetch POST
    async function postForm(url, data) {
        const res = await fetch(url, { method: 'POST', body: data });
        if (!res.ok) throw new Error('Request failed');
        return await res.json().catch(() => ({}));
    }
    </script>
</body>
</html>


