# Threema Meets Lumo

Let’s be honest: Threema and Lumo have one thing in common that makes them soulmates in the digital world—Privacy. That’s exactly why we’re marrying them together.

Now, you can bring Lumo AI directly into your Threema groups. Your friends can chat away as usual, secure and encrypted. But the moment someone needs Lumo’s brainpower, they just need to utter the magic word.

Do you know the magic word? Hint: It involves root privileges.

Yes! The magic word is **sudo**.

Just type sudo explain why we need to meet tomorrow night, and Lumo jumps into the conversation, ready to reason, calculate, or joke around.


[Watch Demo Video](https://carlostkd.ch/lumo/Threema+Lumo.mp4)


## Installation Guide

To get this running, you’ll need a dedicated setup. Think of it as your private AI butler living in a server room (or a Raspberry Pi under your desk).

Prerequisites

   A Second Threema ID: This will be the identity of your Lumo bot.
   Hardware: A Raspberry Pi connected to a desktop is preferred for 24/7 uptime, but any standard desktop (Windows,      Linux, or macOS) will work fine.

Step 1: Install Dependencies

We need Playwright to handle the browser automation. Run the appropriate command for your OS:

Windows (PowerShell):

```
npm install -g playwright
npx playwright install
```

Linux/macOS:

```
npm install -g playwright
npx playwright install-deps
npx playwright install
```

Step 2: Launch the Bot

Run the script to initialize the connection:

`node threema_lumo.js`

Step 3: Authenticate

   A QR code will appear in your desktop.
   Open your Second Threema ID on your phone.
   Scan the QR code to link the bot.
   Crucial Step: Leave this session open! If you close the terminal, the bot goes to sleep. This is why a Raspberry Pi is the    hero of this story it runs quietly in the background while you focus on the important stuff.

Step 4: Create the Group

   Create a new Threema group.
   Add your Second Threema ID (the bot).
   Invite your friends.

## How to Use

That’s it! You are now live.

Every message starting with **sudo** triggers Lumo. Everything else remains a standard, encrypted chat between humans.

Examples:

   sudo weather in Zurich
   sudo summarize the last 10 messages
   sudo tell me a joke about encryption

Did I say this was free? No? Well, allow me to correct that egregious oversight.

## It's free.

Yes. Really. Completely. Totally. Unapologetically free.

No hidden fees. No "starting at $4.99/month" nonsense. No trial that expires faster than your motivation on a Monday morning. 

No premium tier that unlocks the real features while the free version gives you a glorified loading screen.

Just you, your Raspberry Pi, your second Threema ID, and a privacy-first AI buddy living rent-free in your group chat.

The only thing you'll spend? A few minutes setting it up. And maybe a little electricity. But hey, your Pi was already sitting there doing nothing 

give it a purpose.

So go ahead. **sudo** your way into the future. Your wallet can stay right where it is closed and happy.
