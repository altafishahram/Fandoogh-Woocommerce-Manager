(function () {
  "use strict";

  function copyWithFallback(value) {
    var area = document.createElement("textarea");
    area.value = value;
    area.setAttribute("readonly", "");
    area.style.position = "fixed";
    area.style.opacity = "0";
    document.body.appendChild(area);
    area.select();
    var copied = false;
    try {
      copied = document.execCommand("copy");
    } catch (error) {
      copied = false;
    }
    document.body.removeChild(area);
    return copied ? Promise.resolve() : Promise.reject(new Error("copy_failed"));
  }

  function copyTrackingCode(value) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      return navigator.clipboard.writeText(value).catch(function () {
        return copyWithFallback(value);
      });
    }
    return copyWithFallback(value);
  }

  document.addEventListener("click", function (event) {
    var button = event.target.closest("[data-fandoogh-copy]");
    if (!button) {
      return;
    }

    var target = document.getElementById(button.getAttribute("data-fandoogh-copy") || "");
    var value = target && target.textContent ? target.textContent.trim() : "";
    if (!value) {
      return;
    }

    var label = button.querySelector("[data-fandoogh-copy-label]");
    var original = label ? label.textContent : "";
    copyTrackingCode(value).then(function () {
      button.classList.add("is-copied");
      if (label) {
        label.textContent = "کپی شد";
      }
      window.setTimeout(function () {
        button.classList.remove("is-copied");
        if (label) {
          label.textContent = original;
        }
      }, 1800);
    });
  });
}());
