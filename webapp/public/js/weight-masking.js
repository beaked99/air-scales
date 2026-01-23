// Weight Masking for Free Users
// File: public/js/weight-masking.js

/**
 * Format and mask weight based on subscription status
 * @param {number} weight - Weight value in lbs
 * @param {boolean} hasSubscription - Whether user has active subscription
 * @returns {string} Formatted weight string (e.g., "15,220 lbs" or "XX,220 lbs")
 */
function maskWeight(weight, hasSubscription) {
  if (weight === null || weight === undefined || isNaN(weight)) {
    return '-- lbs';
  }

  weight = Math.round(weight);

  // Subscribed users see full weight
  if (hasSubscription) {
    return weight.toLocaleString() + ' lbs';
  }

  // Free users see masked weight (XX,XXX for values >= 1000)
  if (weight >= 1000) {
    // Get last 3 digits
    const lastThree = (weight % 1000).toString().padStart(3, '0');
    return 'XX,' + lastThree + ' lbs';
  }

  // Under 1000 lbs, show full weight even for free users
  return weight.toLocaleString() + ' lbs';
}

/**
 * Update weight display element with masked value
 * @param {HTMLElement} element - Element to update
 * @param {number} weight - Weight value
 * @param {boolean} hasSubscription - Subscription status
 */
function updateWeightDisplay(element, weight, hasSubscription) {
  if (!element) return;
  element.textContent = maskWeight(weight, hasSubscription);
}

// Export for module use if needed
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { maskWeight, updateWeightDisplay };
}
