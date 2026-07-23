// Function to generate a random number between min and max
function getRandomNumber(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

// Function to randomly choose between withdrew and deposited
function chooseTransaction() {
  return Math.random() < 0.5 ? 'got a VIP plan' : (Math.random() < 0.5 ? 'deposited' : 'withdrew');
}


// Function to display the popup
function displayPopup() {
  var popup = document.getElementById('popup');
  popup.style.display = 'block';
  setTimeout(function() {
    popup.style.display = 'none';
  }, 3000); // Hide after 3 seconds
}

// Function to update the sentence with random country and amount
function updateSentence() {
  var countries = ['France', 'Germany', 'Italy', 'Spain']; // Example European countries
  var country = countries[getRandomNumber(0, countries.length - 1)];
  var amount = getRandomNumber(100000, 900000);
  var transaction = chooseTransaction();

  var sentence = "An investor from " + country + " " + transaction + " $" + amount + "...";
  document.getElementById('sentence').textContent = sentence;

  displayPopup(); // Display the popup after updating the sentence
}

// Event listener for close button
document.getElementById('close').addEventListener('click', function() {
  document.getElementById('popup').style.display = 'none';
});

// Initial update of the sentence
updateSentence();

// Update the sentence every 7 seconds
setInterval(updateSentence, 7000);