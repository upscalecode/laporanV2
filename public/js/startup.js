window.addEventListener("error", function (event) {
        var box = document.getElementById("startupError");
        var message = document.getElementById("startupErrorMessage");
        if (box) box.hidden = false;
        if (message)
          message.textContent =
            "JavaScript: " +
            (event.message || "gagal dimuat") +
            (event.lineno ? " (baris " + event.lineno + ")" : "");
      });
