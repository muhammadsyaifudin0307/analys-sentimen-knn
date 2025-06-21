function togglePassword() {
  const passwordInput = document.getElementById("password");
  const icon = document.querySelector(".password-toggle i");

  if (passwordInput.type === "password") {
    passwordInput.type = "text";
    icon.classList.remove("bi-eye");
    icon.classList.add("bi-eye-slash");
  } else {
    passwordInput.type = "password";
    icon.classList.remove("bi-eye-slash");
    icon.classList.add("bi-eye");
  }
}

// Prevent form resubmission
if (window.history.replaceState) {
  window.history.replaceState(null, null, window.location.href);
}

var toastElList = [].slice.call(document.querySelectorAll(".toast"));
var toastList = toastElList.map(function (toastEl) {
  return new bootstrap.Toast(toastEl);
});

toastList.forEach(function (toast) {
  toast.show(); // Display the toast
});

var ctx = document.getElementById("myChart").getContext("2d");
var myChart = new Chart(ctx, {
  type: "pie",
  data: {
    labels: ["Positif", "Negatif"],
    datasets: [
      {
        data: [150, 50], // Data aktual dari analisis sentimen
        backgroundColor: ["#28a745", "#dc3545"],
      },
    ],
  },
});
