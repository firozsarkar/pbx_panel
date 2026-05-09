<script>
function fetchLiveCalls() {
    $.getJSON('http://your-server-ip/livecall.php', function(response) {
        if(response.status == 'success') {
            console.log(response.data); // এখানে আপনি ডাটা টেবিল আপডেট করতে পারেন
            $('#call-count').text(response.total_calls);
        }
    });
}

// প্রতি ৫ সেকেন্ড পর পর ডাটা ফেচ করবে
setInterval(fetchLiveCalls, 5000);
</script>
