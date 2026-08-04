<!DOCTYPE html>
<html>
<head><title>Video Test</title></head>
<body style="background:#000; margin:0;">

  <p style="color:white; font-family:sans-serif; padding:20px;">
    If you see the video below, the file is loading correctly.<br>
    If you see nothing or an error, the path or file is the problem.
  </p>

  <video controls autoplay muted loop playsinline
         style="width:100%; max-width:1000px; display:block; margin:20px;"
         poster="../sports.jpeg">
    <source src="hero_bg.mp4" type="video/mp4">
    <p style="color:red; padding:20px;">Browser cannot play this video.</p>
  </video>

  <p style="color:#aaa; font-family:sans-serif; padding:20px;">
    Open F12 console and look for any red errors mentioning hero_bg.mp4
  </p>

</body>
</html>
