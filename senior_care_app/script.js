
(() => {
  "use strict";

  const STORAGE_KEY = "seniorDailyCareDataV1";

  const DAYS = ["S", "M", "T", "W", "T", "F", "S"];

  const DAY_NAMES = [
    "Sunday", "Monday", "Tuesday", "Wednesday",
    "Thursday", "Friday", "Saturday"
  ];

  const TYPES = {
    medication: {
      label: "Medication",
      icon: "💊",
      className: "medication"
    },
    meal: {
      label: "Meal",
      icon: "🍽️",
      className: "meal"
    },
    water: {
      label: "Water",
      icon: "💧",
      className: "water"
    },
    exercise: {
      label: "Exercise",
      icon: "🏃",
      className: "exercise"
    },
    rest: {
      label: "Rest / Sleep",
      icon: "😴",
      className: "rest"
    },
    other: {
      label: "Other",
      icon: "📋",
      className: ""
    }
  };

  // Default activities displayed on the first visit.
  const defaultData = () => ({
    user: null,

    activities: [
      {
        id: "a1",
        name: "Morning tablets",
        type: "medication",
        time: "08:00",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a2",
        name: "Breakfast",
        type: "meal",
        time: "08:30",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a3",
        name: "Drink water",
        type: "water",
        time: "11:00",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a4",
        name: "Lunch",
        type: "meal",
        time: "13:00",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a5",
        name: "Evening walk",
        type: "exercise",
        time: "17:30",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a6",
        name: "Dinner",
        type: "meal",
        time: "20:00",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a7",
        name: "Go to bed",
        type: "rest",
        time: "22:00",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      },
      {
        id: "a8",
        name: "Take a short rest",
        type: "rest",
        time: "15:00",
        days: [0, 1, 2, 3, 4, 5, 6],
        status: {}
      }
    ],

    people: [],
    water: {},
    accounts: []
  });

  // Load previously saved information.
  let data;

  try {
    data =
      JSON.parse(localStorage.getItem(STORAGE_KEY)) ||
      defaultData();
  } catch (error) {
    data = defaultData();
  }

  if (!data.activities) {
    data.activities = defaultData().activities;
  }

  if (!data.people) {
    data.people = [];
  }

  if (!data.accounts) {
    data.accounts = [];
  }

  if (!data.water) {
    data.water = {};
  }

  let currentPage = "auth";
  let authMode = "signin";
  let selectedDays = [0, 1, 2, 3, 4, 5, 6];
  let activePersonId = null;
  let toastTimer;

  const app = document.getElementById("app");
  const toastEl = document.getElementById("toast");

  // Returns today's date in YYYY-MM-DD format.
  const todayKey = () => {
    const d = new Date();

    return (
      `${d.getFullYear()}-` +
      `${String(d.getMonth() + 1).padStart(2, "0")}-` +
      `${String(d.getDate()).padStart(2, "0")}`
    );
  };

  // Escape user-entered text before putting it into HTML.
  const esc = (value) =>
    String(value ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;"
    })[c]);

  // Save application information in this browser.
   const save = () => {
  const savedData = {
    ...data,
    user: null,
    accounts: []
  };

  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify(savedData)
  );
};
  // Return the activities scheduled for today.
  const todayActivities = () => {
    const day = new Date().getDay();

    return data.activities
      .filter((activity) => (activity.days || []).includes(day))
      .sort((a, b) => a.time.localeCompare(b.time));
  };

  // Return the status of an activity for today.
  const statusFor = (activity) =>
    (activity.status || {})[todayKey()] || "";

  // Convert 24-hour time into 12-hour time.
  const fmtTime = (time) => {
    const [h, m] = time.split(":").map(Number);
    const suffix = h >= 12 ? "PM" : "AM";

    return `${(h % 12) || 12}:${String(m).padStart(2, "0")} ${suffix}`;
  };

  const displayDate = () =>
    new Intl.DateTimeFormat(undefined, {
      weekday: "long",
      day: "numeric",
      month: "long"
    }).format(new Date());

  // Display a temporary notification message.
  const showToast = (message) => {
    toastEl.textContent = message;
    toastEl.classList.add("show");

    clearTimeout(toastTimer);

    toastTimer = setTimeout(() => {
      toastEl.classList.remove("show");
    }, 2800);
  };
  // Track reminders already shown during this page session.
const remindersShown = new Set();

// Check whether any scheduled activities need a reminder.
function checkTaskReminders() {
  if (!data.user || !activePersonId) {
    return;
  }

  if (!Array.isArray(data.activities)) {
    return;
  }

  const now = new Date();
  const currentMinutes = now.getHours() * 60 + now.getMinutes();
  const dayOfWeek = now.getDay();
  const dateKey = todayKey();

  data.activities.forEach((task) => {
    // Only remind on days when the task is scheduled.
    const days = Array.isArray(task.days)
      ? task.days.map(Number)
      : [];

    if (!days.includes(dayOfWeek)) {
      return;
    }

    // Skip activities already marked Done or Skip today.
    const status = task.status?.[dateKey] || null;

    if (status === "done" || status === "skip") {
      return;
    }

    // The task time must be in HH:MM format.
    const parts = String(task.time || "").split(":");
    const hours = Number(parts[0]);
    const minutes = Number(parts[1]);

    if (
      parts.length < 2 ||
      !Number.isInteger(hours) ||
      !Number.isInteger(minutes) ||
      hours < 0 ||
      hours > 23 ||
      minutes < 0 ||
      minutes > 59
    ) {
      return;
    }

    const scheduledMinutes = hours * 60 + minutes;
    const difference = currentMinutes - scheduledMinutes;

    // Display a reminder from 5 minutes before until
    // 10 minutes after the scheduled time.
    if (difference < -5 || difference > 10) {
      return;
    }

    const reminderKey =
      `${dateKey}:${activePersonId}:${task.id}`;

    // Avoid showing the same reminder repeatedly.
    if (remindersShown.has(reminderKey)) {
      return;
    }

    remindersShown.add(reminderKey);

    showToast(
      `Reminder: ${task.name} is scheduled for ${task.time}.`
    );
  });
}

// Check every minute while this page is open.
setInterval(checkTaskReminders, 60 * 1000);
  // Request JSON from the PHP backend.
async function fetchJson(url, options = {}) {
  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    headers: {
      "Accept": "application/json",
      ...(options.headers || {})
    }
  });

  const text = await response.text();

  let result;

  try {
    result = JSON.parse(text);
  } catch {
    throw new Error(
      "The server returned an invalid response. HTTP " +
      response.status
    );
  }

  if (!response.ok || !result.success) {
    throw new Error(
      result.message || "The request failed."
    );
  }

  return result;
}

// Load the selected person's activities and water intake from MySQL.
async function loadPersonData() {
  try {
    const peopleResult = await fetchJson("api/people.php");
    const people = peopleResult.people || [];

    // Keep the Care Circle synchronized with MySQL.
    data.people = people;

    if (people.length === 0) {
    activePersonId = null;
    data.activities = [];
    data.water[todayKey()] = 0;
    return;
    }

    // Keep the selected person if available; otherwise use the first.
    const person = people.find(
      p => Number(p.id) === Number(activePersonId)
    ) || people[0];

    activePersonId = Number(person.id);

    const [tasksResult, waterResult] = await Promise.all([
      fetchJson(
        "api/tasks.php?person_id=" +
        encodeURIComponent(activePersonId)
      ),
      fetchJson(
        "api/water.php?person_id=" +
        encodeURIComponent(activePersonId)
      )
    ]);

    // Convert database field names to the format used by the dashboard.
    data.activities = (tasksResult.tasks || []).map(task => ({
      id: String(task.id),
      name: task.title,
      type: task.category,
      time: String(task.task_time).slice(0, 5),
      days: Array.isArray(task.days)
        ? task.days.map(Number)
        : [],
      status: task.today_status
        ? { [todayKey()]: task.today_status }
        : {}
    }));

    data.water[todayKey()] = Math.max(
      0,
      Math.min(8, Number(waterResult.glasses) || 0)
    );
    checkTaskReminders();

  } catch (error) {
    console.error("Database data loading failed:", error);

    showToast(
      "Could not load activities from MySQL: " + error.message
    );
  }
}

  // Mark an activity as done or skipped.
 // Save Done/Skip status through the PHP backend.
const setStatus = async (id, status) => {
  if (!activePersonId) {
    showToast("Please select a person first.");
    return;
  }

  if (!["done", "skip"].includes(status)) {
    showToast("Invalid activity status.");
    return;
  }

  try {
    await fetchJson("api/task_status.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        task_id: Number(id),
        status: status
      })
    });

    // Refresh activities from MySQL.
    await loadPersonData();

    render();

    showToast(
      status === "done"
        ? "Activity marked as done."
        : "Activity skipped for today."
    );

  } catch (error) {
    console.error("Could not save task status:", error);

    showToast(
      "Could not save status: " + error.message
    );
  }
};

  const iconFor = (activity) =>
    TYPES[activity.type]?.icon || TYPES.other.icon;

  // Common navigation for the application pages.
  const nav = () => `
  <header class="topbar">

    <div class="brand">
      <span class="logo">🌿</span>
      <span>Daily Care</span>
    </div>

    <nav class="nav" aria-label="Main navigation">
      ${
        [
          ["today", "Today"],
          ["timetable", "Timetable"],
          ["care", "Care circle"],
          ["about", "About"]
        ].map(([id, label]) => `
          <button
            data-page="${id}"
            class="${currentPage === id ? "active" : ""}">
            ${label}
          </button>
        `).join("")
      }
    </nav>

    ${
      data.people.length > 0
        ? `
          <div class="person-picker">
            <label for="activePersonSelect">Person:</label>

            <select id="activePersonSelect">
              ${
                data.people.map(person => `
                  <option
                    value="${person.id}"
                    ${
                      Number(person.id) === Number(activePersonId)
                        ? "selected"
                        : ""
                    }>
                    ${esc(person.name)}
                  </option>
                `).join("")
              }
            </select>
          </div>
        `
        : ""
    }

    <div class="user-nav">
      <span>${esc(data.user?.name || "Guest")}</span>
      <button class="signout" id="signout">Sign out</button>
    </div>

  </header>
`;
  // Handle registration and sign-in through the PHP backend.
async function handleAuth(event) {
  event.preventDefault();

  const form = new FormData(event.currentTarget);

  const email = String(form.get("email") || "")
    .trim()
    .toLowerCase();

  const password = String(form.get("password") || "");

  const isRegister = authMode === "register";

  const payload = {
    email: email,
    password: password
  };

  if (isRegister) {
    payload.name = String(form.get("name") || "").trim();
    payload.role = String(form.get("role") || "");

    if (!payload.name) {
      showToast("Please enter your name.");
      return;
    }

    if (!["senior", "guardian"].includes(payload.role)) {
      showToast("Please select an account type.");
      return;
    }
  }

  if (!email || !password) {
    showToast("Please enter your email and password.");
    return;
  }

  const button = event.currentTarget.querySelector(
    'button[type="submit"]'
  );

  if (button) {
    button.disabled = true;
    button.textContent = isRegister
      ? "Creating account..."
      : "Signing in...";
  }

  try {
    const response = await fetch(
      isRegister ? "api/register.php" : "api/login.php",
      {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body: JSON.stringify(payload)
      }
    );

    const responseText = await response.text();
    let result;

    try {
      result = JSON.parse(responseText);
    } catch {
      throw new Error(
        "Unexpected server response. Check the PHP error log."
      );
    }

    if (!response.ok || !result.success) {
      throw new Error(
        result.message || "Authentication failed."
      );
    }

    // Use the user returned by PHP.
    data.user = result.user;
    currentPage = "today";

    await loadPersonData();

    render();



    showToast(
      isRegister
        ? "Account created successfully."
        : "Signed in successfully."
    );

  } catch (error) {
    showToast(error.message || "Unable to contact the PHP backend.");

  } finally {
    if (button && button.isConnected) {
      button.disabled = false;
      button.textContent = isRegister
        ? "Create account"
        : "Sign in";
    }
  }
}

  // --------------------------------
  // SIGN IN AND CREATE ACCOUNT
  // --------------------------------

  function renderAuth() {
    app.innerHTML = `
      <div class="auth-screen">

        <section>

          <div class="auth-brand">
            <span class="logo">🌿</span>
            <span>Senior Citizen Daily Care</span>
          </div>

          <h1 class="hero-title">
            Every tablet,<br>
            meal and walk,<br>
            right on time.
          </h1>

          <p class="hero-copy">
            A calm daily timetable for senior citizens,
            with reminders that speak up and a guardian
            who can look in from anywhere.
          </p>

          <div class="preview-list">

            <div class="preview-item">
              <span class="preview-time">8:00 AM</span>
              <span>💊 &nbsp;Morning tablets</span>
            </div>

            <div class="preview-item">
              <span class="preview-time">1:00 PM</span>
              <span>🍽️ &nbsp;Lunch</span>
            </div>

            <div class="preview-item">
              <span class="preview-time">5:30 PM</span>
              <span>🏃 &nbsp;Evening walk</span>
            </div>

            <div class="preview-item">
              <span class="preview-time">10:00 PM</span>
              <span>😴 &nbsp;Go to bed</span>
            </div>

          </div>

        </section>

        <form class="auth-card" id="authForm">

          <div class="tabs">

            <button
              type="button"
              data-auth="signin"
              class="${authMode === "signin" ? "active" : ""}">
              Sign in
            </button>

            <button
              type="button"
              data-auth="register"
              class="${authMode === "register" ? "active" : ""}">
              Create account
            </button>

          </div>

         ${
            authMode === "register"
                ? `
                <div class="field">
                    <label for="authName">Full name</label>

                    <input
                    id="authName"
                    name="name"
                    autocomplete="name"
                    placeholder="Enter your name"
                    minlength="2"
                    maxlength="100"
                    required>
                </div>

                <div class="field">
                    <label for="authRole">Account type</label>

                    <select id="authRole" name="role" required>
                    <option value="senior">Senior citizen</option>
                    <option value="guardian">Guardian</option>
                    </select>
                </div>
                `
                : ""
            }
          <div class="field">
            <label for="authEmail">Email</label>
            <input
              id="authEmail"
              name="email"
              type="email"
              autocomplete="email"
              placeholder="you@example.com"
              required>
          </div>

          <div class="field">
            <label for="authPassword">Password</label>
            <input
              id="authPassword"
              name="password"
              type="password"
              minlength="4"
              autocomplete="${
                authMode === "signin"
                  ? "current-password"
                  : "new-password"
              }"
              placeholder="Enter password"
              required>
          </div>

          <button class="primary wide" type="submit">
            ${authMode === "signin" ? "Sign in" : "Create account"}
          </button>

          <p class="auth-note">
            ${
              authMode === "signin"
                ? "New here? Choose Create account to register."
                : "Your demo account is stored only in this browser."
            }
          </p>

        </form>

      </div>
    `;

    app.querySelectorAll("[data-auth]").forEach((button) => {
      button.addEventListener("click", () => {
        authMode = button.dataset.auth;
        renderAuth();
      });
    });

    document
      .getElementById("authForm")
      .addEventListener("submit", handleAuth);
  }

  // Process account creation and sign-in.
  

  // --------------------------------
  // COMMON APPLICATION LAYOUT
  // --------------------------------

  function renderShell(content) {
    app.innerHTML = `${nav()}<main>${content}</main>`;

    app.querySelectorAll("[data-page]").forEach((button) => {
      button.addEventListener("click", () => {
        currentPage = button.dataset.page;
        render();
      });
    });
    // Switch between people managed by the guardian.
const personSelect = document.getElementById("activePersonSelect");

personSelect?.addEventListener("change", async (event) => {
  const newPersonId = Number(event.currentTarget.value);

  if (!Number.isInteger(newPersonId) || newPersonId < 1) {
    showToast("Please select a valid person.");
    return;
  }

  activePersonId = newPersonId;

  await loadPersonData();

  render();

  const selectedPerson = data.people.find(
    person => Number(person.id) === newPersonId
  );

  showToast(
    "Showing " + (selectedPerson?.name || "selected person") + "."
  );
});
    document.getElementById("signout")?.addEventListener(
        "click",
        async (event) => {
            const button = event.currentTarget;

            button.disabled = true;
            button.textContent = "Signing out...";

            try {
            const response = await fetch("api/logout.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                "Accept": "application/json"
                }
            });

            const text = await response.text();
            let result;

            try {
                result = JSON.parse(text);
            } catch {
                throw new Error("The server returned an unexpected response.");
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || "Sign out failed.");
            }

            // Clear the frontend's authenticated user.
            data.user = null;
            currentPage = "auth";
            authMode = "signin";

            save();
            render();

            showToast("Signed out successfully.");

            } catch (error) {
            showToast(error.message || "Could not sign out.");

            if (button.isConnected) {
                button.disabled = false;
                button.textContent = "Sign out";
            }
            }
        }
    );
  }

  // --------------------------------
  // TODAY DASHBOARD
  // --------------------------------

  function renderToday() {
    const activities = todayActivities();

    const doneCount = activities.filter(
      (a) => statusFor(a) === "done" || statusFor(a) === "skip"
    ).length;

    const next =
      activities.find((a) => !statusFor(a)) || activities[0];

    const water = Number(data.water[todayKey()] || 0);

    const list = activities.map((activity) => {
      const status = statusFor(activity);

      const overdue =
        !status &&
        activity.time < new Date().toTimeString().slice(0, 5);

      return `
        <div class="activity-row">

          <div class="activity-icon ${
            TYPES[activity.type]?.className || ""
          }">
            ${iconFor(activity)}
          </div>

          <div>
            <div class="activity-name">
              ${esc(activity.name)}
            </div>

            <div class="activity-time">
              ${fmtTime(activity.time)}

              ${
                status
                  ? `<span class="status ${
                      status === "done" ? "done" : ""
                    }">
                      ${status === "done" ? "Done" : "Skipped"}
                    </span>`
                  : overdue
                    ? `<span class="status overdue">Overdue</span>`
                    : ""
              }

            </div>
          </div>

          <div class="row-actions">

            ${
              status
                ? `<button class="small-btn" data-undo="${activity.id}">
                    Undo
                   </button>`
                : `
                  <button class="done-btn" data-done="${activity.id}">
                    Done
                  </button>

                  <button class="small-btn" data-skip="${activity.id}">
                    Skip
                  </button>
                `
            }

          </div>

        </div>
      `;
    }).join("");

    renderShell(`
      <div class="page-head">

        <h1>
          Good ${
            new Date().getHours() < 12
              ? "morning"
              : new Date().getHours() < 17
                ? "afternoon"
                : "evening"
          },
          ${esc((data.user?.name || "there").split(" ")[0]).toUpperCase()}
        </h1>

        <div class="subheading">${displayDate()}</div>

      </div>

      <div class="today-grid">

        <div class="stack">

          <section class="card up-next">

            <div class="big-icon">
              ${next ? iconFor(next) : "🎉"}
            </div>

            <div>
              <p class="eyebrow">Up next</p>

              <p class="time">
                ${next ? fmtTime(next.time) : "All done!"}
              </p>

              <p class="task-name">
                ${
                  next
                    ? esc(next.name)
                    : "You have completed today's activities"
                }
              </p>
            </div>

          </section>

          <section class="card">

            <h2>Today's activities</h2>

            <div class="activity-list">
              ${
                list ||
                `<p class="empty">
                   No activities are scheduled for today.
                   Add some in Timetable.
                 </p>`
              }
            </div>

          </section>

        </div>

        <aside class="stack">

          <section class="card light progress-card">

            <div
              class="progress-ring"
              style="--progress:${
                activities.length
                  ? Math.round(doneCount / activities.length * 100)
                  : 0
              }%">

              <strong>${doneCount}/${activities.length}</strong>

            </div>

            <div>
              <h3>Progress</h3>
              <p>${Math.max(0, activities.length - doneCount)} left today</p>
            </div>

          </section>

          <section class="card">

            <h3>Water</h3>

            <p class="water-count">${water} of 8 glasses</p>

            <div class="glasses" aria-label="Water glasses">
              ${Array.from({ length: 8 }, (_, i) => `
                <span
                  class="glass ${i < water ? "filled" : ""}"
                  title="Glass ${i + 1}">
                </span>
              `).join("")}
            </div>

            <div class="water-actions">
              <button class="primary" id="addWater">
                + Add a glass
              </button>

              <button
                class="secondary"
                id="removeWater"
                aria-label="Remove one glass">
                −
              </button>
            </div>

          </section>

        </aside>

      </div>
    `);

    // Mark activities done.
    app.querySelectorAll("[data-done]").forEach((button) => {
      button.addEventListener("click", () => {
        setStatus(button.dataset.done, "done");
      });
    });

    // Skip activities.
    app.querySelectorAll("[data-skip]").forEach((button) => {
      button.addEventListener("click", () => {
        setStatus(button.dataset.skip, "skip");
      });
    });

    // Undo a previous status.
    app.querySelectorAll("[data-undo]").forEach((button) => {
      button.addEventListener("click", () => {
        const activity = data.activities.find(
          (a) => a.id === button.dataset.undo
        );

        if (activity?.status) {
          delete activity.status[todayKey()];
        }

        save();
        render();

        showToast("Activity status reset.");
      });
    });

    // Save today's water intake through the PHP backend.
async function saveWaterIntake(glasses) {
  if (!activePersonId) {
    showToast("Please select a person first.");
    return;
  }

  try {
    const result = await fetchJson("api/water.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        person_id: Number(activePersonId),
        glasses: glasses
      })
    });

    // Reload the current values from MySQL.
    await loadPersonData();

    render();

    showToast(
      result.message || "Water intake saved successfully."
    );

  } catch (error) {
    console.error("Water tracking error:", error);

    showToast(
      "Could not save water intake: " + error.message
    );
  }
}

// Add one glass.
document.getElementById("addWater").addEventListener("click", () => {
  const currentGlasses = Number(data.water[todayKey()] || 0);

  if (currentGlasses >= 8) {
    showToast("Your daily tracking goal is 8 glasses.");
    return;
  }

  saveWaterIntake(currentGlasses + 1);
});

// Remove one glass.
document.getElementById("removeWater").addEventListener("click", () => {
  const currentGlasses = Number(data.water[todayKey()] || 0);

  if (currentGlasses <= 0) {
    showToast("Water intake cannot be less than zero.");
    return;
  }

  saveWaterIntake(currentGlasses - 1);
});
  }

  // --------------------------------
  // TIMETABLE PAGE
  // --------------------------------

  function renderTimetable() {
    const activities = [...data.activities].sort(
      (a, b) => a.time.localeCompare(b.time)
    );

    const rows = activities.map((activity) => `
      <div class="activity-row timetable-row">

        <div class="activity-icon ${
          TYPES[activity.type]?.className || ""
        }">
          ${iconFor(activity)}
        </div>

        <div>

          <div class="activity-name">
            ${esc(activity.name)}
          </div>

          <div class="activity-time">
            ${fmtTime(activity.time)} · ${
              (activity.days || []).length === 7
                ? "Every day"
                : (activity.days || [])
                    .map((d) => DAY_NAMES[d].slice(0, 3))
                    .join(", ") || "No repeat days"
            }
          </div>

        </div>

        <button class="delete-btn" data-delete="${activity.id}">
          Delete
        </button>

      </div>
    `).join("");

    renderShell(`
      <div class="page-head">

        <h1>Timetable</h1>

        <div class="subheading">
          Daily routine for ${esc(data.user?.name || "you")}
        </div>

      </div>

      <div class="columns">

        <section class="card">

          <h2>All activities</h2>

          <div class="activity-list">
            ${
              rows ||
              `<p class="empty">
                No activities yet. Add your first activity.
              </p>`
            }
          </div>

        </section>

        <section class="card light">

          <h2>Add an activity</h2>

          <form id="activityForm">

            <div class="field">
              <label for="activityName">
                What needs to be done?
              </label>

              <input
                id="activityName"
                name="name"
                placeholder="e.g. Blood pressure tablet"
                required
                maxlength="100">
            </div>

            <div class="field">
              <label for="activityType">Type</label>

              <select id="activityType" name="type">
                ${
                  Object.entries(TYPES).map(([key, type]) => `
                    <option value="${key}">
                      ${type.icon} ${type.label}
                    </option>
                  `).join("")
                }
              </select>
            </div>

            <div class="field">
              <label for="activityTime">Time</label>

              <input
                id="activityTime"
                name="time"
                type="time"
                value="09:00"
                required>
            </div>

            <label>Repeat on</label>

            <div class="weekdays">
              ${
                DAYS.map((day, i) => `
                  <button
                    type="button"
                    class="day-toggle ${
                      selectedDays.includes(i) ? "selected" : ""
                    }"
                    data-day="${i}"
                    aria-pressed="${selectedDays.includes(i)}">
                    ${day}
                  </button>
                `).join("")
              }
            </div>

            <button class="primary wide" type="submit">
              Add to timetable
            </button>

          </form>

        </section>

      </div>
    `);

    // Select or deselect repeat days.
    app.querySelectorAll("[data-day]").forEach((button) => {
      button.addEventListener("click", () => {
        const day = Number(button.dataset.day);

        selectedDays = selectedDays.includes(day)
          ? selectedDays.filter((d) => d !== day)
          : [...selectedDays, day].sort();

        button.classList.toggle(
          "selected",
          selectedDays.includes(day)
        );

        button.setAttribute(
          "aria-pressed",
          String(selectedDays.includes(day))
        );
      });
    });

    // Add an activity to the timetable.
    document
  .getElementById("activityForm")
  .addEventListener("submit", async (event) => {
    event.preventDefault();

    const form = new FormData(event.currentTarget);

    const name = String(form.get("name") || "").trim();
    const type = String(form.get("type") || "");
    const time = String(form.get("time") || "");

    if (!activePersonId) {
      showToast("No person is selected. Please sign in again.");
      return;
    }

    if (!name) {
      showToast("Enter an activity name.");
      return;
    }

    if (selectedDays.length === 0) {
      showToast("Choose at least one repeat day.");
      return;
    }

    const submitButton = event.currentTarget.querySelector(
      'button[type="submit"]'
    );

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Saving...";
    }

    try {
      // Save the activity and repeat days in MySQL.
      const result = await fetchJson("api/tasks.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          person_id: Number(activePersonId),
          title: name,
          category: type,
          task_time: time,
          days: [...selectedDays]
        })
      });

      // Reload the timetable from the database.
      await loadPersonData();

      selectedDays = [0, 1, 2, 3, 4, 5, 6];

      render();

      showToast(
        result.message || "Activity added successfully."
      );

    } catch (error) {
      console.error("Could not add activity:", error);

      showToast(
        "Could not save activity: " + error.message
      );

    } finally {
      if (submitButton && submitButton.isConnected) {
        submitButton.disabled = false;
        submitButton.textContent = "Add to timetable";
      }
    }
  });

    // Delete an activity.
    // Delete an activity through the PHP backend.
app.querySelectorAll("[data-delete]").forEach((button) => {
  button.addEventListener("click", async (event) => {
    const deleteButton = event.currentTarget;
    const taskId = Number(deleteButton.dataset.delete);

    const activity = data.activities.find(
      (item) => Number(item.id) === taskId
    );

    if (!activity || !Number.isInteger(taskId) || taskId < 1) {
      showToast("Activity not found.");
      return;
    }

    // Ask for confirmation before deleting database records.
    if (!confirm(`Delete "${activity.name}" from your timetable?`)) {
      return;
    }

    deleteButton.disabled = true;
    deleteButton.textContent = "Deleting...";

    try {
      const result = await fetchJson("api/delete_task.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          task_id: taskId
        })
      });

      // Refresh the timetable using the database records.
      await loadPersonData();

      render();

      showToast(
        result.message || "Activity deleted successfully."
      );

    } catch (error) {
      console.error("Delete activity error:", error);

      showToast(
        "Could not delete activity: " + error.message
      );

    } finally {
      if (deleteButton.isConnected) {
        deleteButton.disabled = false;
        deleteButton.textContent = "Delete";
      }
    }
  });
});
  }

  // --------------------------------
  // CARE CIRCLE PAGE
  // --------------------------------

  async function renderCare() {
  let weeklyDays = [];

  if (activePersonId) {
    try {
      const weeklyResult = await fetchJson(
        "api/weekly_progress.php?person_id=" +
        encodeURIComponent(activePersonId)
      );

      weeklyDays = weeklyResult.days || [];

    } catch (error) {
      console.error("Weekly progress loading failed:", error);
      showToast("Could not load weekly progress.");
    }
  }
    const completed = data.activities.filter(
      (a) => statusFor(a) === "done"
    ).length;

    const overdue = data.activities.filter(
      (a) =>
        !statusFor(a) &&
        a.time < new Date().toTimeString().slice(0, 5)
    );

    const people = data.people.map((person, i) => `
      <div class="care-person">

        <h3>${esc(person.name)}</h3>

        <p>Caregiver / family member</p>

        <button
          class="delete-btn"
          data-remove-person="${person.id}"">
          Remove person
        </button>

      </div>
    `).join("");

    renderShell(`
      <div class="page-head">

        <h1>Care circle</h1>

        <div class="subheading">
          See how each routine is going and manage alerts.
        </div>

      </div>

      <div class="columns">

        <section class="card">

          <h2>${esc(data.user?.name || "Senior Citizen")}</h2>

          <div class="week-bars">
            ${
                weeklyDays.map(day => `
                <div
                    class="week-day"
                    title="${esc(day.done)} of ${esc(day.scheduled)} activities completed">

                    <div
                    class="week-bar"
                    style="height:${Math.max(
                        5,
                        Math.round(Number(day.percentage || 0) * 0.7)
                    )}px">
                    </div>

                    <span>${esc(day.day)}</span>
                </div>
                `).join("")
                || "<p>Weekly progress is unavailable.</p>"
            }
            </div>

          <p>
            Tasks completed today:
            <strong>${completed}</strong>
          </p>

          <p>
            <span class="pill">${overdue.length} overdue today</span>

            ${
              overdue.length
                ? overdue.map((a) => esc(a.name)).join(", ")
                : "No overdue activities right now."
            }
          </p>

          <div style="margin-top:28px">

            <h3>Family members and caregivers</h3>

            ${
              people ||
              `<p class="empty">
                No additional people added yet.
              </p>`
            }

          </div>

        </section>

        <aside class="stack">

          <section class="card light">

            <h2>Add a person</h2>

            <form id="personForm">

              <div class="field">

                <label for="personName">Name</label>

                <input
                  id="personName"
                  name="name"
                  required
                  maxlength="80"
                  placeholder="Enter family member's name">

              </div>

              <button class="primary wide" type="submit">
                Add person
              </button>

            </form>

          </section>

          <section class="card light">

            <h2>Alerts and display</h2>

            <p>
              Pop-up reminders can appear while this page is open.
              Browser notifications may require permission.
            </p>

            <button class="primary" id="allowNotifications">
              Allow notifications
            </button>

            <button
              class="secondary"
              id="testReminder"
              style="margin:8px 0 0 5px">
              Test a reminder
            </button>

            <p style="font-size:14px">
              Keep this app open for in-page reminders.
              This demo does not send alerts to another device.
            </p>

          </section>

        </aside>

      </div>
    `);

    // Add a caregiver or family member.
    // Add a person through the PHP backend.
document
  .getElementById("personForm")
  .addEventListener("submit", async (event) => {
    event.preventDefault();

    const form = new FormData(event.currentTarget);
    const name = String(form.get("name") || "").trim();

    if (name.length < 2 || name.length > 100) {
      showToast("Name must contain 2 to 100 characters.");
      return;
    }

    const submitButton = event.currentTarget.querySelector(
      'button[type="submit"]'
    );

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Adding...";
    }

    try {
      // Save the person to MySQL.
      const result = await fetchJson("api/people.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          name: name
        })
      });

      // Reload the people list from the database.
      await loadPersonData();

      render();

      showToast(
        result.message || "Person added successfully."
      );

    } catch (error) {
      console.error("Add person error:", error);

      showToast(
        "Could not add person: " + error.message
      );

    } finally {
      if (submitButton && submitButton.isConnected) {
        submitButton.disabled = false;
        submitButton.textContent = "Add person";
      }
    }
  });

    // Remove a family member.
    // Remove a person through the PHP backend.
app.querySelectorAll("[data-remove-person]").forEach((button) => {
  button.addEventListener("click", async (event) => {
    const removeButton = event.currentTarget;
    const personId = Number(removeButton.dataset.removePerson);

    const person = data.people.find(
      (p) => Number(p.id) === personId
    );

    if (!person || !Number.isInteger(personId) || personId < 1) {
      showToast("Person not found.");
      return;
    }

    const confirmed = confirm(
      `Permanently delete ${person.name} and all their activities, ` +
      `completion history, and water records? This cannot be undone.`
    );

    if (!confirmed) {
      return;
    }

    removeButton.disabled = true;
    removeButton.textContent = "Removing...";

    try {
      const result = await fetchJson("api/delete_person.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          person_id: personId
        })
      });

      // Reload the remaining people and their data from MySQL.
      activePersonId = null;
      await loadPersonData();

      render();

      showToast(
        result.message || "Person removed successfully."
      );

    } catch (error) {
      console.error("Remove person error:", error);

      showToast(
        "Could not remove person: " + error.message
      );

    } finally {
      if (removeButton.isConnected) {
        removeButton.disabled = false;
        removeButton.textContent = "Remove person";
      }
    }
  });
});

    // Request browser notification permission.
    document
      .getElementById("allowNotifications")
      .addEventListener("click", async () => {
        if (!("Notification" in window)) {
          return showToast(
            "This browser does not support notifications."
          );
        }

        try {
          const permission = await Notification.requestPermission();

          showToast(
            permission === "granted"
              ? "Notifications enabled."
              : "Notification permission was not granted."
          );

        } catch (error) {
          showToast("Could not request notification permission.");
        }
      });

    // Test a reminder.
    document
      .getElementById("testReminder")
      .addEventListener("click", () => {
        showToast("Reminder: check your next scheduled activity.");

        if (
          "Notification" in window &&
          Notification.permission === "granted"
        ) {
          new Notification("Daily Care reminder", {
            body: "Please check your next scheduled activity."
          });
        }
      });
  }

  // --------------------------------
  // ABOUT PAGE
  // --------------------------------

  function renderAbout() {
    renderShell(`
      <div class="page-head">
        <h1>About Daily Care</h1>
      </div>

      <div class="about-grid">

        <section class="card">

          <h2>Why it exists</h2>

          <p>
            Older adults can find it hard to keep track of medicines,
            meals, exercise, sleep and daily instructions, and family
            members are often at work during the day. Daily Care keeps
            a personal timetable and reminders at the right moment.
          </p>

          <h2 style="margin-top:28px">Goals</h2>

          <p>
            A personal timetable for each senior. Timely reminders.
            Fewer missed activities. Guardians who can manage routines.
            An interface that is simple and accessible.
          </p>

        </section>

        <section class="card light">

          <h2>How it works</h2>

          <ol class="steps">

            <li>
              <span class="step-number">1</span>
              <span>Sign in as a senior or a guardian</span>
            </li>

            <li>
              <span class="step-number">2</span>
              <span>Create the daily timetable</span>
            </li>

            <li>
              <span class="step-number">3</span>
              <span>Get alerts when an activity is due</span>
            </li>

            <li>
              <span class="step-number">4</span>
              <span>Information is saved in this browser</span>
            </li>

          </ol>

        </section>

      </div>
    `);
  }

  // --------------------------------
  // MAIN PAGE CONTROLLER
  // --------------------------------

  function render() {
    if (!data.user) {
      return renderAuth();
    }

    if (currentPage === "today") {
      return renderToday();
    }

    if (currentPage === "timetable") {
      return renderTimetable();
    }

    if (currentPage === "care") {
      return renderCare();
    }

    if (currentPage === "about") {
      return renderAbout();
    }

    currentPage = "today";

    renderToday();
  }

  // --------------------------------
  // SCHEDULED REMINDERS
  // --------------------------------

  // Checks every 20 seconds whether an activity is due.
  // The app must remain open for this feature to work.
  setInterval(() => {
    if (!data.user) return;

    const now = new Date();

    const time =
      `${String(now.getHours()).padStart(2, "0")}:` +
      `${String(now.getMinutes()).padStart(2, "0")}`;

    const due = todayActivities().filter(
      (activity) =>
        activity.time === time &&
        !statusFor(activity)
    );

    due.forEach((activity) => {
      const key = `${todayKey()}-${activity.id}-${time}`;

      // Prevent repeatedly showing the same reminder this session.
      if (sessionStorage.getItem(key)) return;

      sessionStorage.setItem(key, "shown");

      showToast(`Reminder: ${activity.name}`);

      if (
        "Notification" in window &&
        Notification.permission === "granted"
      ) {
        new Notification("Daily Care reminder", {
          body: `It's time for: ${activity.name}`
        });
      }
    });
  }, 20000);

  // Start the application.
  async function initializeApp() {
  try {
    const response = await fetch("api/me.php", {
      method: "GET",
      credentials: "same-origin",
      headers: {
        "Accept": "application/json"
      }
    });

    const result = await response.json();

    if (response.ok && result.success && result.user) {
        data.user = result.user;
        currentPage = "today";

        await loadPersonData();
    
    } else {
      // No valid session: show the sign-in page.
      data.user = null;
      currentPage = "auth";
      authMode = "signin";
    }

    render();

  } catch (error) {
    console.error("Session initialization failed:", error);

    data.user = null;
    currentPage = "auth";
    authMode = "signin";

    render();
    showToast("Could not connect to the PHP backend.");
  }
}

initializeApp();
})();